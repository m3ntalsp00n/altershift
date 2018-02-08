<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Services extends Eloquent {
    
	protected $table = 'services';

	public function users() {
		return $this->hasOne('Models\User', 'id', 'users_id');
	}

	public function servicesDetail() {
		return $this->hasMany('Models\ServicesDetails', 'services_id', 'id');
	}

	public function availability() {
		return $this->hasMany(
			'Models\Availability',
			'services_id',
			'id'
		);
	}
}