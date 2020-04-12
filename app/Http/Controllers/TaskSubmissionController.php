<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskSubmission;
use App\Http\Resources\TaskSubmissionResource;
use App\Task;
use App\Slack;
use App\User;
use App\TaskSubmission;
use App\TrackUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use App\Http\Classes\ResponseTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Classes\ActivityTrait;

class TaskSubmissionController extends Controller
{

    use ResponseTrait;
    use ActivityTrait;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }
        $submissions = TaskSubmission::orderBy('created_at', 'desc')->with(['user', 'task', 'graded_by:id,firstname,lastname,email,username'])->get();
        if ($submissions) {
            // return TaskSubmissionResource::collection($submissions);
            return $this->sendSuccess($submissions, 'AllTasks submissions fetched', 200);
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreTaskSubmission $request)
    {
        //$u = auth()->user();
        //return $u;

        //dd('ddi');

//         if (!auth('api')->user()->hasRole(['intern'])) {
//         //if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin', 'intern'])) {    
//             return $this->ERROR('You dont have the permission to perform this action');
//         }

        //$data = $request->validated();

        // Check if the User is found in the trackUser
        if (!TrackUser::where('user_id', $data['user_id'])->first()) {
            // if (!TrackUser::where('user_id', auth()->user()->id)) {
            // return $this->errorResponse('User does not belong to this track', 422);
            return $this->sendError('User does not belong to this track', 422, []);
        }

        // Check if the Task Submission date has past => done
        if (Task::find($data['task_id'])->first()->deadline < Carbon::now()) {
            // return $this->errorResponse('Submission date has elapsed', 422);
            return $this->sendError('Deadline date has elapsed', 422, []);
        }

        // Check if Status is still open for submission.
        if (Task::find($data['task_id'])->first()->status == 'CLOSED') {
            // return $this->errorResponse('Task submission Closed', 422);
            return $this->sendError('Task submission Closed', 422, []);
        }

        $task = TaskSubmission::create($request->all());
        if ($task) {
            // return new TaskSubmissionResource($task);
            return $this->sendSuccess($task, 'Task submitted successfully', 200);
        }

    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin', 'intern'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }

        if ($submission = TaskSubmission::whereId($id)->where('user_id', auth('api')->user()->id)->first()) {
            // return new TaskSubmissionResource($submission);
            return $this->sendSuccess($submission, 'Task submission fetched', 200);
            
        } else {
            // return $this->errorResponse('Submission not found', 404);
            return $this->sendError('Submission not found', 404, []);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }
        
        $intern_submission = TaskSubmission::destroy($id);
        if ($intern_submission) {
            return $this->sendSuccess($intern_submission, 'Task Submitted deleted', 200);
        }
        return $this->sendError('Internal server error.', 500, []);
    }
    
    /**
     * View all interns score for a task resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function view_all_intern_grades(Request $request, $id){

        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }

        $interns_task_submission = TaskSubmission::
        with(['task', 'user'])
            ->where('task_id', $id)->get();

        if ($interns_task_submission) {
            return new TaskSubmissionResource($interns_task_submission);
        } else {
            // return $this->errorResponse('Task has not been graded', 404);
            return $this->sendError('Task has not been graded', 404, []);
        }
    }

    public function grade_task_for_interns(Request $request, $id){

        $validator = Validator::make($request->all(), [
            'grade_score' => 'required|array',
        ]);

        if ($validator->fails()) {
            return $this->sendError('', 400, $validator->errors());
        }

        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }

        $interns_task_submissions = TaskSubmission::where('task_id', $id)->get();

        $scores = $request->input('grade_score');

        foreach ($scores as $score) {
            //dd($value);
            $data = [
                'grade_score' => $score['grade_score']
            ];

            TaskSubmission::where('task_id', $id)->update($data);
        }

    }

    public function grade_intern_task(Request $request, $id){

        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }

        $validator = Validator::make($request->all(), [
            'grade_score' => 'bail|required',
            'user_id' => 'bail|required|integer'
        ]);

        if ($validator->fails()) {
            return $this->sendError('', 400, $validator->errors());
        }

        $user_id = $request->user_id;

        $task = Task::find($id);
        if(!$task){
            return $this->sendError('Task doesnt exists', 404, []);
        }

        $intern_submission = TaskSubmission::where('user_id', $user_id)->where('task_id', $id)->first();

        if ($intern_submission) {
            $data = [
                'grade_score' => (int)$request->input('grade_score'),
            ];

            // SEND NOTIFICATION HERE
            $intern_submission->grade_score = $request->input('grade_score');
            $intern_submission->is_graded = 1;
            $intern_submission->graded_by = auth()->id();
            $res = $intern_submission->save();
            
            // $res =  $intern_submission->update($data);

            if($res){
                $user = auth()->user();
                $message = $user->firstname . ' ' . $user->lastname . ' ('. $user->email .') graded ' . $intern_submission->user->firstname . ' ('. $intern_submission->user->email . ') ' . $intern_submission->user->lastname. ', Score ' . $request->input('grade_score'). ' for task: '. $intern_submission->task->title;
                $this->logAdminActivity($message);

                return $this->sendSuccess($intern_submission, 'Task submission successfully graded', 200);
            }else{
                return $this->sendError('Task submission wasn not graded', 422, []);
            }

            //jude
            // return TaskSubmission::find($id)->update($data);
        } else {
            // return $this->errorResponse('Task has not been graded', 404);
            return $this->sendError('Intern has not submitted this task', 404, []);
        }
    }

    public function intern_view_task_grade(Request $request, $user_id, $id){

        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin', 'intern'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }

        $intern_submission = TaskSubmission::where('user_id', $user_id)->where('task_id', $id)->get();

        if ($intern_submission) {
            // return TaskSubmissionResource($intern_submission);
            return $this->sendSuccess($intern_submission, 'Task submission fetched', 200);
        } else {
            // return $this->errorResponse('Task has not been graded', 404);
            return $this->sendError('Task has not been graded', 404, []);
        }
    }

    public function intern_view_task_grades($id){

        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin', 'intern'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }

        $task_submission_grades = TaskSubmission::where('task_id', $id)->get();

        if ($task_submission_grades) {
            // return TaskSubmissionResource($task_submission_grades);
            return $this->sendSuccess($task_submission_grades, 'Task submission fetched', 200);
        } else {
            // return $this->errorResponse('Task has not been graded', 404);
            return $this->sendError('Task has not been graded', 404, []);
        }
    }

    //retrieve_interns_submission
    public function retrieve_interns_submission($id)
    {

        $user = auth()->user();

        // if (!auth('api')->user()->hasAnyRole(['intern', 'admin', 'superadmin'])) {
        //     return $this->ERROR('You dont have the permission to perform this action');
        // }

        $submissions = TaskSubmission::where('task_id', $id)->where('user_id', $user->id)->with('user')->get();
        if ($submissions) {
            // return TaskSubmissionResource::collection($submissions);
            return $this->sendSuccess($submissions, 'AllTasks submissions fetched', 200);
        }
    }
    
    public function admin_retrieve_interns_submission($id)
    {
        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }
        $submissions = TaskSubmission::where('task_id', $id)->with('user')->get();
        if ($submissions) {
            // return TaskSubmissionResource::collection($submissions);
            return $this->sendSuccess($submissions, 'AllTasks submissions fetched', 200);
        }
    }
    
    public function delete_interns_submissions($taskId)
    {
        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }
        
        $interns_submissions = TaskSubmission::where('task_id', $taskId)->delete();
        
        if ($interns_submissions) {
            return $this->sendSuccess($interns_submissions, 'All Submissions deleted', 200);
        }
        return $this->sendError('Internal server error.', 500, []);
    }
    
    public function delete_all_submission(){
        
        if (!auth('api')->user()->hasAnyRole(['admin', 'superadmin'])) {
            return $this->ERROR('You dont have the permission to perform this action');
        }
        
        if (TaskSubmission::delete()) {
            return $this->sendSuccess($interns_submissions, 'All Submissions deleted', 200);
        }
        return $this->sendError('Internal server error.', 500, []);
    }

    public function submit(Request $request)
    {

        // check if task exist
        $checkTask = Task::where('id', $request->task_id)->first();

        if(!$checkTask){
            return $this->sendError('task does not exists', 404, []);
        }

        $check = TaskSubmission::where('task_id', $request->task_id)->where('user_id', $request->user_id)->first();

        if($check){
            return $this->sendError('You have already submitted this task', 422, []);
        }

        // Check if the User is found in the trackUser
        if (!TrackUser::where('user_id', $request['user_id'])->get()) {
            // if (!TrackUser::where('user_id', auth()->user()->id)) {
            // return $this->errorResponse('User does not belong to this track', 422);
            return $this->sendError('User does not belong to this track', 422, []);
        }

        // Check if the Task Submission date has past => done
        // if ($checkTask->deadline->lte(Carbon::now())) {
            //if ($checkTask->deadline < Carbon::now()) {
            if ($checkTask->deadline < Carbon::now()) {
            // return $this->errorResponse('Submission date has elapsed', 422);
            return $this->sendError('Deadline date has elapsed', 422, []);
        }

        // Check if Status is still open for submission.
        if ($checkTask->status == 'CLOSED') {
            // return $this->errorResponse('Task submission Closed', 422);
            return $this->sendError('Task submission Closed', 422, []);
        }

        $task = TaskSubmission::create($request->all());
        if ($task) {
            // return new TaskSubmissionResource($task);
            return $this->sendSuccess($task, 'Task submitted successfully', 200);
        }

    }

    public function promote(){
        $users = User::where('role', 'intern')->where('stage', 1)->get();

        foreach($users as $user){
            //get all their submissions
            $submissions = $user->submissions;
            $submissionsArray = $submissions->pluck('task_id')->all();
            $courses = $user->courses;
            $tasksArray = array();
            foreach($courses as $course){
                $aTask = Task::where('course_id', $course->id)->orderBy('created_at', 'asc')->first();
                array_push($tasksArray, $aTask->id);
            }

            $diff = array_diff($tasksArray, $submissionsArray);
            $stage = $user->stage;

            if(count($diff) == 0){
                //promote user
                if($stage == 1){
                    $slack_id =  $user->slack_id;
                    Slack::removeFromChannel($slack_id, 1);
                    Slack::addToChannel($slack_id, 2);
                    $user->stage = 2;
                    $user->save();
                }
            }
            // else{
            //     //demote if in stage 1
            //     if($stage == 2){
            //         $slack_id =  $user->slack_id;
            //         Slack::removeFromChannel($slack_id, 2);
            //         Slack::addToChannel($slack_id, 1);
            //         $user->stage = 1;
            //         $user->save();
            //     }
            // }
        }
        return $this->sendSuccess($user, 'successfully promoted interns', 200);

    }

    public function promote_to_stage_2(){
        $users = User::where('role', 'intern')->where('stage', 1)->get();

        foreach($users as $user){
            //get all their submissions
            $submissions = $user->submissions;
            $submissionsArray = $submissions->pluck('task_id')->all();
            $courses = $user->courses;
            $tasksArray = array();
            foreach($courses as $course){
                $aTask = Task::where('course_id', $course->id)->orderBy('created_at', 'asc')->first();
                array_push($tasksArray, $aTask->id);
            }

            $diff = array_diff($tasksArray, $submissionsArray);
            if(count($diff) == 0){
                //promote user
                $slack_id =  $user->slack_id;
                Slack::removeFromChannel($slack_id, 1);
                Slack::addToChannel($slack_id, 2);
                $user->stage = 2;
                $user->save();
            }else{
                continue;
            }
        }
        return $this->sendSuccess($user, 'successfully promoted interns', 200);
    }

    public function promote_to_stage_3(){
        $users = User::where('role', 'intern')->where('stage', 2)->get();

        foreach($users as $user){
            //get all their submissions
            $submissions = $user->submissions;
            $submissionsArray = $submissions->pluck('task_id')->all();
            $courses = $user->courses;
            $tasksArray = array();
            foreach($courses as $course){
                $aTask = Task::where('course_id', $course->id)->where('id', '!=', 88)->where('id', '!=', 87)->orderBy('created_at', 'asc')->get();
                $arrT = $aTask->pluck('id')->all();
                // array_push($tasksArray, $aTask->id);
                array_merge($tasksArray, $arrT);
            }

            $diff = array_diff($tasksArray, $submissionsArray);
            if(count($diff) == 0){
                //promote user
                $slack_id =  $user->slack_id;
                Slack::removeFromChannel($slack_id, 2);
                Slack::addToChannel($slack_id, 3);
                $user->stage = 3;
                $user->save();
            }else{
                continue;
            }
        }
        return $this->sendSuccess($user, 'successfully promoted interns', 200);
    }

    public function promote_admins_to_stage_3(){
        $users = User::where('role', 'admin')->get();

        foreach($users as $user){
                //promote user
                $slack_id =  $user->slack_id;
                // Slack::removeFromChannel($slack_id, 1);
                Slack::addToChannel($slack_id, 3);
                $user->stage = 3;
                $user->save();
        }
        return $this->sendSuccess($user, 'successfully promoted admin', 200);
    }

    public function remove_stage_3(){
        $users = User::where('role', 'intern')->where('stage', 3)->get();

        foreach($users as $user){
            $slack_id =  $user->slack_id;
            Slack::removeFromChannel($slack_id, 3);
            Slack::addToChannel($slack_id, 2);
            $user->stage = 2;
            $user->save();
        }

    }


    public function test_promotion(){
        $users = User::where('role', 'intern')->where('stage', 2)->get();
        $count = 0;
        $rr = array();
        foreach($users as $user){
            //get all their submissions
            $submissions = $user->submissions;
            $submissionsArray = $submissions->pluck('task_id')->all();
            $courses = $user->courses;
            $tasksArray = array();
            foreach($courses as $course){
                $aTask = Task::where('course_id', $course->id)->where('id', '!=', 88)->where('id', '!=', 87)->orderBy('created_at', 'asc')->get();
                $arrT = $aTask->pluck('id')->all();
                $tasksArray = array_merge($tasksArray, $arrT);
            }

            $diff = array_diff($tasksArray, $submissionsArray);

            if(count($diff) == 0){
                array_push($rr, $user->username);
                $count += 1;
            }else{
                continue;
            }
        }
        return $this->sendSuccess([$count, $rr], 'successfully promoted interns', 200);
    }

    public function grading_task_submissions(Request $request){
        $task_id = $request->task_id;
        $grade = $request->grade;

        if(!$task_id){  
            return $this->sendError('No Task ID', 404, []);
        }

        if(!$grade){  
            return $this->sendError('No Grade', 404, []);
        }

        $res = TaskSubmission::where('task_id', $task_id)->update([
            'grade_score' => $grade,
            'is_graded' => true,
            'graded_by' => 2
        ]);

        return $this->sendSuccess($res, 'successfully graded task', 200);
    }

}
