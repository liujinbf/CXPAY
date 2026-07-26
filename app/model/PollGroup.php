<?php

declare(strict_types=1);

namespace app\model;

use illuminate\database\eloquent\Model;

class PollGroup extends Model
{
    protected $table = 'cx_poll_group';
    public $timestamps = false;
}
