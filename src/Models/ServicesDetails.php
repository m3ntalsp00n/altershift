<?php
namespace Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class ServicesDetails extends Eloquent {
	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'services_detail';

	protected $hidden = ['services_id'];

	public function services() {
		return $this.belongsToMany('Services', 'availability');
	}

	public function availability() {
		return $this->hasMany('Models\Availability');
	}
}