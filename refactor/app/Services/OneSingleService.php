<?php

/**
 * This file is part of the repository package.
 *
 * (c) DigitalTolk
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * PHP Version 8.2
 *
 * @category Notification_Class
 * @package  DTApi\Notification
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */

namespace DTApi\Services;

use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Class OneSingleService
 *
 * @category Notification_Class
 * @package  DTApi\Notification
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */
class OneSingleService
{
    /**
     * The Logger instance for push logs.
     *
     * @var Logger
     */
    private $pusher_logger;

    /**
     * OneSingleService constructor.
     * Initializes the logger for push notifications.
     */
    public function __construct()
    {
        $this->pusher_logger = new Logger('push_logger');
        $this->pusher_logger->pushHandler(
            new StreamHandler(
                storage_path(
                    'logs/push/laravel-' . date('Y-m-d')
                    . '.log'
                ),
                Logger::DEBUG
            )
        );
        $this->pusher_logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get the OneSignal App ID based on the application environment.
     *
     * @return string The OneSignal App ID.
     */
    public static function getOnesignalAppId(): string
    {
        return env('APP_ENV') === 'prod'
            ? config('app.prodOnesignalAppID')
            : config('app.devOnesignalAppID');
    }

    /**
     * Get the OneSignal REST API Authorization Key based on the application
     * environment.
     *
     * @return string The OneSignal REST API Authorization Key.
     */
    public static function getOnesignalRestAuthKey(): string
    {
        $apiKey = env('APP_ENV') === 'prod'
            ? config('app.prodOnesignalApiKey')
            : config('app.devOnesignalApiKey');
        return sprintf("Authorization: Basic %s", $apiKey);
    }

    /**
     * Send a push notification using OneSignal.
     *
     * @param array $fields The fields for the notification.
     * @param int   $jobId  The ID of the job associated with the notification.
     *
     * @return array The response from OneSignal or an exception message.
     */
    public function sendPushNotification(array $fields, int $jobId)
    {
        try {
            $fields['app_id'] = self::getOnesignalAppId();
            $client = new \GuzzleHttp\Client();
            $url = config('one_signal_api_url');
            /**
             * TODO: Add configuration
             * https://onesignal.com/api/v1 in app.one_signal_api_url
             */
            $response = $client->post(
                $url . '/notifications',
                [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => self::getOnesignalRestAuthKey(),
                ],
                'body' => json_encode($fields),
                ]
            );
            return [$response->getBody()->getContents()];
        } catch (\Exception $exception) {
            return ['Exception: ' . $exception->getMessage()];
        }
    }
}
