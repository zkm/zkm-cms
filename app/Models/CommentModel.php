<?php

namespace App\Models;

use CodeIgniter\Model;

class CommentModel extends Model
{
    protected $table         = 'comments';
    protected $returnType    = 'array';
    protected $allowedFields = ['post_id', 'author', 'author_email', 'author_url', 'body', 'type', 'approved', 'parent_id', 'wp_id', 'created_at'];
}
