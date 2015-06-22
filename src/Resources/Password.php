<?php
namespace DreamFactory\Core\User\Resources;

use DreamFactory\Core\Resources\UserPasswordResource;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\User\Models\UserConfig;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Utility\ServiceHandler;
use DreamFactory\Core\Services\Email\BaseService as EmailService;
use DreamFactory\Core\Exceptions\ServiceUnavailableException;
use DreamFactory\Library\Utility\ArrayUtils;

class Password extends UserPasswordResource
{
    /**
     * {@inheritdoc}
     */
    protected static function sendPasswordResetEmail(User $user)
    {
        $email = $user->email;

        /** @var $config UserConfig */
        $config = UserConfig::instance();

        if (empty($config)) {
            throw new InternalServerErrorException('Unable to load system configuration.');
        }

        $emailServiceId = $config->password_email_service_id;

        if (!empty($emailServiceId)) {

            try {
                /** @var EmailService $emailService */
                $emailService = ServiceHandler::getServiceById($emailServiceId);

                if (empty($emailService)) {
                    throw new ServiceUnavailableException("Bad service identifier '$emailServiceId'.");
                }

                $data = [];
                $templateId = $config->password_email_template_id;

                if (!empty($templateId)) {
                    $data = $emailService::getTemplateDataById($templateId);
                }

                if (empty($data) || !is_array($data)) {
                    throw new ServiceUnavailableException("No data found in default email template for password reset.");
                }

                $data['to'] = $email;
                $data['first_name'] = $user->first_name;
                $data['last_name'] = $user->last_name;
                $data['name'] = $user->name;
                $data['confirm_code'] = $user->confirm_code;
                $data['link'] = url('password/reset/' . urlencode($user->confirm_code));

                $emailService->sendEmail($data, ArrayUtils::get($data, 'body_text'),
                    ArrayUtils::get($data, 'body_html'));

                return true;
            } catch (\Exception $ex) {
                throw new InternalServerErrorException("Error processing password reset.\n{$ex->getMessage()}");
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected static function isAllowed(User $user)
    {
        if (null === $user) {
            throw new NotFoundException("User not found in the system.");
        }

        if ($user->is_sys_admin) {
            throw new UnauthorizedException('You are not authorized to reset/change password for the account ' .
                $user->email);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getApiDocInfo()
    {
        $path = '/' . $this->getServiceName() . '/' . $this->getFullPathName();
        $eventPath = $this->getServiceName() . '.' . $this->getFullPathName('.');
        $apis = [
            [
                'path'        => $path,
                'operations'  => [
                    [
                        'method'           => 'POST',
                        'summary'          => 'changePassword() - Change or reset the current user\'s password.',
                        'nickname'         => 'changePassword',
                        'type'             => 'PasswordResponse',
                        'event_name'       => $eventPath . '.update',
                        'parameters'       => [
                            [
                                'name'          => 'reset',
                                'description'   => 'Set to true to perform password reset.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'login',
                                'description'   => 'Login and create a session upon successful password reset.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Data containing name-value pairs for password change.',
                                'allowMultiple' => false,
                                'type'          => 'PasswordRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            =>
                            'A valid current session along with old and new password are required to change ' .
                            'the password directly posting \'old_password\' and \'new_password\'. <br/>' .
                            'To request password reset, post \'email\' and set \'reset\' to true. <br/>' .
                            'To reset the password from an email confirmation, post \'email\', \'code\', and \'new_password\'. <br/>' .
                            'To reset the password from a security question, post \'email\', \'security_answer\', and \'new_password\'.',
                    ],
                ],
                'description' => 'Operations on a user\'s password.',
            ],
        ];

        $models = [
            'PasswordRequest'  => [
                'id'         => 'PasswordRequest',
                'properties' => [
                    'old_password' => [
                        'type'        => 'string',
                        'description' => 'Old password to validate change during a session.',
                    ],
                    'new_password' => [
                        'type'        => 'string',
                        'description' => 'New password to be set.',
                    ],
                    'email'        => [
                        'type'        => 'string',
                        'description' => 'User\'s email to be used with code to validate email confirmation.',
                    ],
                    'code'         => [
                        'type'        => 'string',
                        'description' => 'Code required with new_password when using email confirmation.',
                    ],
                ],
            ],
            'PasswordResponse' => [
                'id'         => 'PasswordResponse',
                'properties' => [
                    'security_question' => [
                        'type'        => 'string',
                        'description' => 'User\'s security question, returned on reset request when no email confirmation required.',
                    ],
                    'success'           => [
                        'type'        => 'boolean',
                        'description' => 'True if password updated or reset request granted via email confirmation.',
                    ],
                ],
            ],
        ];

        return ['apis' => $apis, 'models' => $models];
    }
}