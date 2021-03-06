<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TaskSubmission extends Model
{
    protected $fillable = ['user_id', 'task_id', 'submission_link', 'comment', 'grade_score', 'is_submitted', 'is_graded'];
    // protected $with = ['task'];

    public function task()
    {
        return $this->belongsTo('App\Task');
    }
    
    public function user()
    {
        return $this->belongsTo('App\User', 'user_id');
    }

    public function graded_by()
    {
        return $this->belongsTo('App\User', 'graded_by');
    }
}
