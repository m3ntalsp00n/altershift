<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Booking extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'bookings';

	protected $hidden = ['created_at', 'updated_at', 'services_id', 'services_detail_id'];

	// public function servicesDetails() {
	// 	return $this->hasManyThrough('Models\ServicesDetails',  'Models\Services');
	// }

	// Reserved, Confirmed, Canceled, Served
	public function services() {
		return $this->hasOne(
			'Models\Services',
			'id',
			'services_id'
		);
	}
	
	public function servicesDetail() {
		return $this->hasOne(
			'Models\ServicesDetails',
			'id',
			'services_detail_id'
		);
	}

	public function recurring() {
		return $this->hasOne(
			'Models\RecurringBookings',
			'bookings_id',
			'id'
		);
	}
}