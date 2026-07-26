<?php

declare(strict_types=1);

namespace app\model;

use illuminate\database\eloquent\Model;

class PollGroupChannel extends Model
{
    protected $table = 'cx_poll_group_channel';
    public $timestamps = false;
}
