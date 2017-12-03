<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class User extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'users';
	/**
	 * The attributes excluded from the model's JSON form.
	 *
	 * @var array
	 */
	protected $hidden = ['password', 'created_at', 'updated_at', 'is_staff'];

    protected $fillable = ['name', 'email', 'password'];

    public function authenticate($username, $password) {
        
    }

	public function services() {
		$this->hasMany('Models\Services');
	}
}