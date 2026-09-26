<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyInterval extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function session()
    {
        return $this->belongsTo(StudySession::class, 'study_session_id');
    }
}
