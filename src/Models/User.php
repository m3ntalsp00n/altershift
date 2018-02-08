<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class User extends Eloquent {
    const DEFAULT_STAFF = 0;

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
	protected $hidden = ['password', 'created_at', 'updated_at'];

    protected $fillable = ['name', 'email', 'id'];

    protected $attributes = [
            'is_staff' => self::DEFAULT_STAFF
        ];


    public $incrementing = false;

    public function authenticate($username, $password) {
        
    }

	public function services() {
		$this->hasMany('Models\Services');
	}
}