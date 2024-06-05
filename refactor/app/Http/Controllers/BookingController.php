<?php

/**
 * This file is part of the DTApi package.
 *
 * (c) DigitalTolk
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * PHP Version 8.2
 *
 * @category Controller_Class
 * @package  DTApi\Http\Controllers
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use Illuminate\Support\Facades\Validator;

/**
 * Class BookingController
 *
 * @category Controller_Class
 * @package  DTApi\Http\Controllers
 * @author   Ali-Raza <arazakalhoro@gmail.com>
 * @license  MIT License (https://opensource.org/licenses/MIT)
 * @link     https://www.example.com/
 */
class BookingController extends Controller
{
    /**
     * Instance of the BookingRepository, used to interact with the booking data.
     *
     * @var BookingRepository
     */
    protected BookingRepository $repository;

    /**
     * BookingController constructor.
     *
     * @param BookingRepository $bookingRepository Instance of the BookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * This function will be used to collect jobs for logged user
     *
     * @param Request $request request param used to filter jobs
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $roles = [
            \config('Admin_role_id'),
            \config('Super_admin_role_id')
        ];
        $response = [];
        if ($user->id == $request->get('user_id')) {
            $response = $this->repository->getUsersJobs($user->id);
        } elseif (in_array($user->user_type, $roles)) {
            $response = $this->repository->getAll($request);
        }
        if (!$response) {
            return response()
                ->json(['message' => 'No data found', 'data' => []], 404);
        }
        return response()
            ->json(['message' => 'All available user jobs', 'data' => $response]);
    }

    /**
     * This function will be used to fetch details of job
     *
     * @param int $id primary key of job
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $job = $this->repository
            ->with('translatorJobRel.user')
            ->find($id);
        if (!empty($job)) {
            return response()->json(
                [
                    'message' => 'Job details not found',
                    'data' => []
                ],
                404
            );
        }
        return response()->json(
            [
                'message' => 'Job details',
                'data' => $job
            ]
        );
    }

    /**
     * This function will be used to store job details
     *
     * @param Request $request use collect data for new job
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $this->repository->store(auth()->user(), $request->all());
        } catch (\Exception $exception) {
            return response()->json([
                'error' => true,
                'message' => 'Något gick fel'
            ]);
        }
        if (!empty($data['status']) && $data['status'] === 'fail') {
            return response()->json($data);
        }
        return response()->json(
            [
                'message' => 'Jobbet har lagts till',
                'data' => $data
            ]
        );
    }

    /**
     * This function will be used to update job details
     *
     * @param $id      primary id for job
     * @param Request $request used to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = array_except($request->all(), ['_token', 'submit']);
        $result = $this->repository->updateJob($id, $data, auth()->user());
        return response()->json(
            [
                'message' => 'Job details has been updated',
                'data' => $result
            ]
        );
    }

    /**
     * This function will be used to store immediate job
     *
     * @param Request $request use to collect data for new job
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function immediateJobEmail(Request $request): JsonResponse
    {
        $data = $this->repository->storeJobEmail($request->all());
        if (!empty($data['fail'])) {
            return response()->json($data);
        }
        return response()->json(
            [
                'message' => 'Immediate job has been added',
                'data' => $data
            ]
        );
    }

    /**
     * This function will be used to collect user's job history
     *
     * @param Request $request use collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHistory(Request $request): JsonResponse
    {
        if ($user_id = $request->get('user_id')) {
            $data = $this->repository->getUsersJobsHistory($user_id, $request);
            return response()->json(
                [
                    'message' => 'User\'s job history',
                    'data' => $data
                ]
            );
        }

        return response()->json(
            [
                'message' => 'No data found',
                'data' => []
            ],
            404
        );
    }

    /**
     * This function will be used to add job into accepted list
     *
     * @param Request $request use to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJob(Request $request): JsonResponse
    {
        $data = $request->all();
        $user = auth()->user();
        $response = $this->repository->acceptJob($data, $user);
        return response($response)->json(
            [
                'message' => 'Job has been added to accepted list',
                'data' => $response
            ]
        );
    }

    /**
     * This function will be used to add specific job into accepted list
     *
     * @param Request $request use to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJobWithId(Request $request): JsonResponse
    {
        $data = $request->get('job_id');
        $user = auth()->user();

        $response = $this->repository->acceptJobWithId($data, $user);

        return response()->json(
            [
                'message' => 'Job has been added to accepted list',
                'data' => $response
            ]
        );
    }

    /**
     * This function will be used to cancel job against user
     *
     * @param Request $request use to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelJob(Request $request): JsonResponse
    {
        $data = $request->all();
        $user = auth()->user();
        $response = $this->repository->cancelJobAjax($data, $user);

        return response()->json(
            [
                'message' => 'Job has been cancelled',
                'data' => $response
            ]
        );
    }

    /**
     * This function will be used end the job
     *
     * @param Request $request use to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function endJob(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->endJob($data);
        return response()->json(
            [
                'message' => 'Job has been ended',
                'data' => $response
            ]
        );
    }

    /**
     * This function will be used to update job and translator details
     *
     * @param Request $request use to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     * */
    public function customerNotCall(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->customerNotCall($data);
        return response()->json(
            [
                'message' => 'Job has been completed by translator',
                'data' => $response
            ]
        );
    }

    /**
     * This function used to collect potential jobs for logged-in user
     *
     * @param Request $request use to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPotentialJobs(Request $request): JsonResponse
    {
        $response = $this->repository->getPotentialJobs(auth()->user());

        return response()->json(
            [
                'message' => 'Potential jobs',
                'data' => $response
            ]
        );
    }

    /**
     * This function will be used to update details of Distance and Job
     *
     * @param Request $request This will be used to filter and collect data
     *
     * @return \Illuminate\Http\JsonResponse
     * */
    public function distanceFeed(Request $request): JsonResponse
    {
        $data = $request->all();
        $validator = Validator::make(
            $request->all(),
            [
                'admincomment' => 'required_if:flagged,true',
            ],
            [
                'admincomment.required_if' => 'Please, add comment',
            ]
        );

        if ($validator->fails()) {
            return response()
                ->json(['message' => $validator->messages()->first()], 422);
        }

        $distance = ($data['distance'] ?? "");
        $time = ($data['time'] ?? "");
        $jobid = ($data['jobid'] ?? "");
        $session = ($data['session_time'] ?? "");
        $admincomment = ($data['admincomment'] ?? "");
        $manually_handled = ((!empty($data['manually_handled'])
            && $data['manually_handled'] == 'true') ? 'yes' : 'no');
        $flagged = ((!empty($data['flagged']) && $data['flagged'] == 'true')
            ? 'yes'
            : 'no'
        );
        $by_admin = ((!empty($data['by_admin']) && $data['by_admin'] == 'true')
            ? 'yes'
            : 'no'
        );

        if (!empty($time) || !empty($distance)) {
            Distance::where('job_id', '=', $jobid)
                ->update(
                    [
                        'distance' => $distance,
                        'time' => $time
                    ]
                );
        }

        if (
            !empty($admincomment)
            || !empty($session)
            || !empty($flagged)
            || !empty($manually_handled)
            || !empty($by_admin)
        ) {
            Job::where('id', '=', $jobid)
                ->update(
                    [
                        'admin_comments' => $admincomment,
                        'flagged' => $flagged,
                        'session_time' => $session,
                        'manually_handled' => $manually_handled,
                        'by_admin' => $by_admin
                    ]
                );
        }
        return response()->json(
            [
                'message' => 'Record updated successfully',
                'data' => []
            ]
        );
    }

    /**
     * This function will be used to re-open a job
     *
     * @param Request $request use to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     * *
     */
    public function reopen(Request $request): JsonResponse
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);
        return response()->json(
            [
                'message' => 'Job has been re-opened',
                'data' => $response
            ]
        );
    }

    /**
     * This function will be used re-send notification
     *
     * @param Request $request used to collect filters and data
     *
     * @return \Illuminate\Http\JsonResponse
     * */
    public function resendNotifications(Request $request): JsonResponse
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');
        return response()->json(['success' => 'Push sent']);
    }

    /**
     * This function will be used send SMS to Translator
     *
     * @param Request $request use to collect data for re-send sms notification
     *
     * @return \Illuminate\Http\JsonResponse
     * */
    public function resendSMSNotifications(Request $request): JsonResponse
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent', 'data' => $job_data]);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()]);
        }
    }
}
