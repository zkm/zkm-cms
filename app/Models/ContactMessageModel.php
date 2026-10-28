<?php

namespace App\Models;

use CodeIgniter\Model;

class ContactMessageModel extends Model
{
    protected $table         = 'contact_messages';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'email', 'message', 'ip', 'user_agent', 'emailed', 'read_at'];

    protected $useTimestamps = true;
    protected $updatedField  = '';
}
