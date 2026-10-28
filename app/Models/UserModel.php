<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table         = 'users';
    protected $returnType    = 'array';
    protected $allowedFields = ['email', 'name', 'password_hash', 'totp_secret', 'last_login_at'];

    protected $useTimestamps = true;
}
