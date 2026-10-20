<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BureaucracyQuestionRequest extends Model
{
    public $timestamps = false;

    protected $fillable = ['session_id', 'request_id', 'question_id', 'response_status'];
}
