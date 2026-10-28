<?php

namespace App\Models;

use CodeIgniter\Model;

class RevisionModel extends Model
{
    protected $table         = 'revisions';
    protected $returnType    = 'array';
    protected $allowedFields = ['post_id', 'title', 'format', 'body', 'excerpt', 'user_id'];

    protected $useTimestamps = true;
    protected $updatedField  = '';
}
