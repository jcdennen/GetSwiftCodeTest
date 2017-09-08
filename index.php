<?php
/**
* GetSwiftCodeTest
*
* @author Jeremy Coltrane Dennen
* @class GetSwiftCodeTest
*/
class GetSwiftCodeTest
{
	/**
	 * Drone speed stored in km per second
	 * @var float
	 */
	public $drone_speed = null;
	
	/**
	 * Current time in format of a UNIX timestamp
	 * @var int
	 */
	public $current_time = null;
	
	/**
	 * Location information related to the depo
	 * @var assoc array
	 */
	public $depo_location = null;

	/**
	 * Array of drones and their data
	 * @var array
	 */
	public $drone_list = null;
	
	/**
	 * Array of packages and their data
	 * @var array
	 */
	public $package_list = null;

	/**
	 * Array of packages and their data
	 * @var array
	 */
	public $assignments = null;

	/**
	 * Array of package IDs that could not be assigned
	 * @var array
	 */
	public $unassigned_package_ids = null;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->drone_speed = 50.0 / 3600; // in km/s
		$this->current_time = time();
		$this->depo_location = array(
			'lat' => -37.816567,
			'lon' => 144.963858,
			'string' => '303 Collins Street, Melbourne, VIC 3000',
		);
		$this->drone_list = $this->curl_getswift_api( 'drones' );
		$this->package_list = $this->curl_getswift_api( 'packages' );
		$this->assignments = array();
		$this->unassigned_package_ids = array();
	}

	/**
	 * HTTP request to GetSwift Code Test API
	 *
	 * @param string $endpoint
	 * @return array
	 */
	private function curl_getswift_api( $endpoint ) {
		$curl = curl_init();
		curl_setopt_array( $curl, array(
			CURLOPT_URL => 'https://codetest.kube.getswift.co/' . $endpoint,
			CURLOPT_RETURNTRANSFER => 1,
		));

		$json_response = curl_exec( $curl );
		curl_close( $curl );

		return json_decode( $json_response, true );
	}

	/**
	 * Calculate distance in kilometers from point x to y
	 * NOTE: uses Haversine formula as implemented at https://rosettacode.org/wiki/Haversine_formula
	 *
	 * @param float $lat_x
	 * @param float $lon_x
	 * @param float $lat_y
	 * @param float $lon_y
	 * @return float
	 */
	private function calculate_distance_in_km( $lat_x, $lon_x, $lat_y, $lon_y ) {
		// convert degrees to radians
		$lat_x = deg2rad( $lat_x );
		$lon_x = deg2rad( $lon_x );
		$lat_y = deg2rad( $lat_y );
		$lon_y = deg2rad( $lon_y );

		$mean_earth_radius = 6371.0;
		$diff_lat = $lat_y - $lat_x;
		$diff_lon = $lon_y - $lon_x;
		$a = sin( $diff_lat / 2 ) * sin( $diff_lat / 2 ) + cos( $lat_x ) * cos( $lat_y ) * sin( $diff_lon / 2 ) * sin( $diff_lon / 2 );
		$c = 2 * asin( sqrt( $a ));
		$distance = $mean_earth_radius * $c;
		return $distance;
	}

	/**
	 * Calculate time (in seconds) it will take to deliver package to its destination from depo
	 *
	 * @param string $package
	 * @return float
	 */
	private function package_delivery_time( $package ) {
		$distance = $this->calculate_distance_in_km( $this->depo_location['lat'], $this->depo_location['lon'], $package['destination']['latitude'], $package['destination']['longitude'] );
		$delivery_time = $distance / ( 50.0 / 3600 );
		return $delivery_time;
	}

	/**
	 * Calculate the time (in seconds) it will take for the drone to return to the depo from it's current location (CL)
	 * NOTE: this function assumes that a drone can only have up to one package assigned to it
	 *
	 * @param string $drone
	 * @return float
	 */
	private function drone_time_until_return( $drone ) {
		if ( empty( $drone['packages'] )) {
			// return time from CL to depo
			$distance = $this->calculate_distance_in_km( $drone['location']['latitude'], $drone['location']['longitude'], $this->depo_location['lat'], $this->depo_location['lon']);
			$return_time = $distance / ( 50.0 / 3600 );
			return $return_time;
		}
		else {
			// calculate distance from drone's CL to its package destination
			$destination_distance = $this->calculate_distance_in_km( $drone['location']['latitude'], $drone['location']['longitude'], $drone['packages'][0]['destination']['latitude'], $drone['packages'][0]['destination']['longitude']);
			// calculate distance from package destination to depo (return)
			$return_distance = $this->calculate_distance_in_km( $drone['packages'][0]['destination']['latitude'], $drone['packages'][0]['destination']['longitude'], $this->depo_location['lat'], $this->depo_location['lon']);
			$return_time = ( $destination_distance + $return_distance ) / ( 50.0 / 3600 );
			return $return_time;
		}
	}

	/**
	 * Assign each package to a drone if possible
	 *
	 * @return JSON string
	 */
	public function assign_packages_to_drones() {
		$d_length = count( $this->drone_list );
		$p_length = count( $this->package_list );

		// sort drones ASC by return timestamp ( the soonest the drone will be back at the station )
		for ( $i = 0; $i < $d_length; $i++ ) { 
			$this->drone_list[$i]['returns'] = $this->current_time + $this->drone_time_until_return( $this->drone_list[$i] );
		}
		usort( $this->drone_list, function ( $drone1, $drone2 ) {
		    if ( $drone1['returns'] == $drone2['returns'] ) return 0;
		    return $drone1['returns'] < $drone2['returns'] ? -1 : 1;
		});

		// sort packages ASC by "leave by" timestamp ( time  package must leave depo by to reach destination in time )
		for ( $i=0; $i < $p_length; $i++ ) { 
			$this->package_list[$i]['leave_by'] = $this->package_list[$i]['deadline'] - $this->package_delivery_time( $this->package_list[$i] );
		}
		usort( $this->package_list, function ( $package1, $package2 ) {
			if ( $package1['leave_by'] == $package2['leave_by'] ) return 0;
			return $package1['leave_by'] < $package2['leave_by'] ? -1 : 1;
		});

		$d_index = 0;
		$p_index = 0;

		while ( $p_index < $p_length ) {
			if ( $d_index < $d_length ) {
				// if drone gets back before package must leave, assign the IDs
				if ( $this->drone_list[$d_index]['returns'] < $this->package_list[$p_index]['leave_by'] ) {
					$this->assignments[] = array( 'droneId' => $this->drone_list[$d_index]['droneId'], 'packageId' => $this->package_list[$p_index]['packageId'] );
					$d_index++;
					$p_index++;
				}		
				else {
					$this->unassigned_package_ids[] = $this->package_list[$p_index]['packageId'];
					$p_index++;
				}
			}
			// leftover packages will be added to unassigned list
			else {
				$this->unassigned_package_ids[] = $this->package_list[$p_index]['packageId'];
				$p_index++;
			}
		}

		// Return JSONified array:
		return json_encode( array( 'assignments' => $this->assignments, 'unassignedPackageIds' => $this->unassigned_package_ids ));
	}
}

// create instance of class and assign packages to drones
$instance = new GetSwiftCodeTest();
echo $instance->assign_packages_to_drones();