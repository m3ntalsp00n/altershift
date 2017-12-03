<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Services extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'services';

	public function users() {
		return $this->hasOne('Models\User', 'id', 'users_id');
	}

	public function servicesDetail() {
		return $this->hasMany('Models\ServicesDetails', 'services_id', 'id');
		// return $this->hasManyThrough(
		// 	'Models\ServicesDetails', 
		// 	'Models\Availability',
		// 	'services_details_id',
		// 	'services_id',
		// 	'services_id',
		// 	'services_details_id'
		// 	);
	}

	public function availability() {
		return $this->hasMany(
			'Models\Availability',
			'services_id',
			'id'
		);
		// return $this->hasManyThrough(
		// 	'Models\Availability', 
		// 	'Models\ServicesDetails',
		// 	'services_id', // foreign key on services_details
		// 	'services_details_id', // foreign on availability
		// 	'services_id', 
		// 	'services_details_id'
		// 	);
	}
}