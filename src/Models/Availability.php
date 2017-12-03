<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Availability extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'availability';

	protected $hidden = ['services_id', 'services_users_id'];

	public function servicesDetails() {
		return $this->hasManyThrough('Models\ServicesDetails',  'Models\Services');
	}
}