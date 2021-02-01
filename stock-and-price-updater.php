<?php

class VARS {
	const DB_HOST = 'localhost';		// ip адресс (хост) вашей БД
	const DB_NAME = 'wploc';			// название вашей БД
	const DB_USER = 'wploc';			// имя пользователя вашей БД
	const DB_PASS = 'wploc';			// пароль вашей БД
	const DB_CHARSET = 'utf8';			// кодировка БД для получения данных (если не знаете что это и зачем, лучше не меняйте)

	const TBL_NAME_POSTMETA				= 'wp_postmeta';				// название таблицы postmeta
	const TBL_NAME_PRODUCT_META_LOOKUP 	= 'wp_wc_product_meta_lookup';	// название таблицы wc_product_meta_lookup
																		// ВНИМАНИЕ! Далее в коде есть довольно сложный запрос
																		// в классе DB, в котором также нужно дополнительно менять
																		// названия таблиц если они поменяются в БД

	const API_APP = 'legeoptika';								// название приложения
	const API_KEY = 'c5ff5316-a7b9-4ab1-a0f4-90a37c503633';		// ключ приложения
	const API_URL = 'https://optima.itigris.ru/%appname%/remoteRemains/list?key=%key%';	// ссылка для доступа где
																						// %appname% - плэйсхолдер для имени
																						// %key% - плэйсхолдер для ключа

	const IS_DEBUG = true; 	// Режим отладки (true - включен, false - выключен)
							// В режиме отладки в БД запись данных не производится, а изменяемые данные выводятся в стандартый поток вывода
}

class Updater {
	private $db;
	private $i_api;

	private $itemlist = [];

	private $arr_update_data_postmeta = [];
	private $arr_update_data_PML = [];

	function __construct() {
		$this->db = new DB( VARS::DB_HOST, VARS::DB_NAME, VARS::DB_USER, VARS::DB_PASS, VARS::DB_CHARSET );
		$this->temp_db = new TempDB( ':memory:' );
		$this->i_api = new ITigrisAPI( VARS::API_URL, VARS::API_APP, VARS::API_KEY, true );
	}

	function __destruct() {
		$this->log = null;
	}

	public function start() {
		$this->itemlist = $this->db->get_items_list();

		$init_data = $this->i_api->get_all();
		$this->temp_db->add_data( $init_data );

		$this->find_changes();

		$this->db->update_postmeta( $this->arr_update_data_postmeta );
		$this->db->update_PML( $this->arr_update_data_PML );
	}

	public function find_changes() {
		$arr_data = $this->db->get_data_by_itemlist( $this->itemlist );

		foreach( $arr_data as $item_id => $item_data ) {
			$stmt = $this->temp_db->pdo->prepare( 'SELECT * FROM `data` WHERE `model`=:model AND `color`=:color' );
			$stmt->execute( array(
				'model' => $item_data[ 'model' ],
				'color' => $item_data[ 'color' ],
			) );
			
			if( ! $q_result = $stmt->fetch( PDO::FETCH_LAZY ) ) {
				if( $item_data[ 'stock_status' ] != 'outofstock' ) {
					$this->arr_update_data_postmeta[ $item_id ][ '_stock_status' ] = 'outofstock';
					$this->arr_update_data_PML[ $item_id ][ 'stock_status' ] = 'outofstock';
				}
				if( $item_data[ 'manage_stock' ] != 'no' ) {
					$this->arr_update_data_postmeta[ $item_id ][ '_manage_stock' ] = 'no';
				}
				if( $item_data[ 'stock' ] != 0 ) {
					$this->arr_update_data_postmeta[ $item_id ][ '_stock' ] = 0;
					$this->arr_update_data_PML[ $item_id ][ 'stock_quantity' ] = 0;
				}

				continue;
			}

			do {
				if( $item_data[ 'regular_price' ] != $q_result[ 'price' ] ) {
					if( ! isset( $this->arr_update_data_postmeta[ $item_id ][ '_regular_price' ] ) ) {
						$koef = $item_data[ 'price' ] / $item_data[ 'regular_price' ];
						$regular_p = $q_result[ 'price' ];
						$price = $regular_p * $koef;
						$this->arr_update_data_postmeta[ $item_id ][ '_regular_price' ] = $regular_p;
						$this->arr_update_data_postmeta[ $item_id ][ '_price' ] = $price;
						$this->arr_update_data_PML[ $item_id ][ 'min_price' ] = $price;
						$this->arr_update_data_PML[ $item_id ][ 'max_price' ] = $price;
						if( $item_data[ 'sale_price' ] != null ) {
							$this->arr_update_data_postmeta[ $item_id ][ '_sale_price' ] = $price;
						}
					}
					if( isset( $this->arr_update_data_postmeta[ $item_id ][ '_regular_price' ] ) ) {
						if( $this->arr_update_data_postmeta[ $item_id ][ '_regular_price' ] < $q_result[ 'price' ] ) {
							$koef = $item_data[ 'price' ] / $item_data[ 'regular_price' ];
							$regular_p = $q_result[ 'price' ];
							$price = $regular_p * $koef;
							$this->arr_update_data_postmeta[ $item_id ][ '_regular_price' ] = $regular_p;
							$this->arr_update_data_postmeta[ $item_id ][ '_sale_price' ] = $price;
							$this->arr_update_data_postmeta[ $item_id ][ '_price' ] = $price;
							$this->arr_update_data_PML[ $item_id ][ 'min_price' ] = $price;
							$this->arr_update_data_PML[ $item_id ][ 'max_price' ] = $price;
							if( $item_data[ 'sale_price' ] != null ) {
								$this->arr_update_data_postmeta[ $item_id ][ '_sale_price' ] = $price;
							}
						}
					}
				} /*else {
					$this->arr_update_data_postmeta[ $item_id ][ '_regular_price' ] = $q_result[ 'price' ];
					$this->arr_update_data_postmeta[ $item_id ][ '_price' ] = $q_result[ 'price' ];
					$this->arr_update_data_PML[ $item_id ][ 'min_price' ] = $q_result[ 'price' ];
					$this->arr_update_data_PML[ $item_id ][ 'max_price' ] = $q_result[ 'price' ];
				}*/

				if( $item_data[ 'stock' ] != $q_result[ 'amount' ] ) {
					if( $q_result[ 'amount' ] = 0 ) {
						if( $item_data[ 'stock_status' ] != 'outofstock' ) {
							$this->arr_update_data_postmeta[ $item_id ][ '_stock_status' ] = 'outofstock';
							$this->arr_update_data_PML[ $item_id ][ 'stock_status' ] = 'outofstock';
						}
						if( $item_data[ 'manage_stock' ] != 'no' ) {
							$this->arr_update_data_postmeta[ $item_id ][ '_manage_stock' ] = 'no';
						}
						if( $item_data[ 'stock' ] != 0 ) {
							$this->arr_update_data_postmeta[ $item_id ][ '_stock' ] = 0;
							$this->arr_update_data_PML[ $item_id ][ 'stock_quantity' ] = 0;
						}
					}
					if ( $q_result[ 'amount' ] > 0 ) {
						if( $item_data[ 'stock_status' ] != 'instock' ) {
							$this->arr_update_data_postmeta[ $item_id ][ '_stock_status' ] = 'instock';
							$this->arr_update_data_PML[ $item_id ][ 'stock_status' ] = 'instock';
						}
						if( $item_data[ 'manage_stock' ] != 'yes' ) {
							$this->arr_update_data_postmeta[ $item_id ][ '_manage_stock' ] = 'yes';
						}
						if( $item_data[ 'stock' ] != $q_result[ 'amount' ] ) {
							$this->arr_update_data_postmeta[ $item_id ][ '_stock' ] += $q_result[ 'amount' ];
							$this->arr_update_data_PML[ $item_id ][ 'stock_quantity' ] += $q_result[ 'amount' ];
						}
					}
				}
			} while( $q_result = $stmt->fetch( PDO::FETCH_LAZY ) );
		}

		if( VARS::IS_DEBUG ) {
			echo "<pre>";
			print_r( $this->arr_update_data_postmeta );
			echo "<br>";
			print_r( $this->arr_update_data_PML );
		}
	}
}


class DB {
	private $db_host;
	private $db_name;
	private $db_user;
	private $db_pass;
	private $db_charset;

	public $pdo;

	function __construct($host, $name, $user, $pass, $charset = 'utf8') {
		$this->db_host 		= $host;
		$this->db_name 		= $name;
		$this->db_user 		= $user;
		$this->db_pass 		= $pass;
		$this->db_charset 	= $charset;

		$this->_connect_db();
	}

	function __destruct() {
		$this->pdo = null;
	}

	/* 
	 * Get items list
	 * 
	 * @return array(
	 * 		model(sku) => product id,
	 * );
	 */
	public function get_items_list() {
		$f_result = [];

		$stmt = $this->pdo->prepare( 'SELECT product_id, sku FROM ' . VARS::TBL_NAME_PRODUCT_META_LOOKUP );
		$stmt->execute();

		while( $q_result = $stmt->fetch( PDO::FETCH_LAZY ) ) {
			$f_result[ $q_result->sku ] = $q_result->product_id;
		}
		
		return $f_result;
	}

	public function get_data_by_itemlist( $itemlist ) {
		$f_result = [];

		$stmt = $this->pdo->prepare( "SELECT `meta_key`, `meta_value` FROM " . VARS::TBL_NAME_POSTMETA . " WHERE `post_id`=:id" );
		$stmt_color = $this->pdo->prepare( "SELECT `name` as `color` FROM `wp_terms` WHERE `term_id`= 
												(SELECT `term_id` FROM `wp_term_taxonomy` WHERE `taxonomy`='pa_color' AND `term_taxonomy_id` IN 
													(SELECT `term_taxonomy_id` FROM `wp_term_relationships` WHERE `object_id`=:id)
												);" );

		foreach( $itemlist as $name => $id ) {
			$d_result = [];

			$stmt->execute( array(
				':id' => $id,
			) );
			$stmt_color->execute( array(
				':id' => $id,
			) );

			while( $q_result = $stmt->fetch( PDO::FETCH_LAZY ) ) {
				$d_result[ $q_result->meta_key ] = $q_result->meta_value;
			}
			$color_result = $stmt_color->fetch( PDO::FETCH_LAZY );

			$f_result[ $id ] = array(
				'model' => $name,
				'color' => $color_result[ 'color' ],
				'regular_price' => $d_result[ '_regular_price' ],
				'sale_price' 	=> $d_result[ '_sale_price' ],
				'price' 		=> $d_result[ '_price' ],
				'stock'			=> $d_result[ '_stock' ],
				'stock_status' 	=> $d_result[ '_stock_status' ],
				'manage_stock' 	=> $d_result[ '_manage_stock' ],
			);
		}
		
		return $f_result;
	}

	public function update_postmeta( $in_data ) {

		if( VARS::IS_DEBUG ) { return; }

		foreach( $in_data as $item_id => $data ) {

			foreach( $data as $field => $value ) {
				$stmt = $this->pdo->prepare( "SELECT `meta_id` FROM " . VARS::TBL_NAME_POSTMETA . " WHERE `post_id`=:id AND `meta_key`=:meta_key" );
				$stmt->execute( array(
					':id' => $item_id,
					':meta_key' => $field,
				) );

				if( ! $q_result = $stmt->fetch( PDO::FETCH_LAZY ) ) {
					continue;
				}

				$stmt_update = $this->pdo->prepare( 'UPDATE ' . VARS::TBL_NAME_POSTMETA . ' SET `meta_value`=:value WHERE `post_id`=:post_id AND `meta_key`=:field' );
				$stmt_update->bindParam( ':field', $field );
				$stmt_update->bindParam( ':value', $value );
				$stmt_update->bindParam( ':post_id', $item_id );
				$stmt_update->execute();
			}
			
		}
		
	}

	public function update_PML( $in_data ) {

		if( VARS::IS_DEBUG ) { return; }

		foreach( $in_data as $item_id => $data ) {

			foreach( $data as $field => $value ) {
				$stmt_update = $this->pdo->prepare( 'UPDATE ' . VARS::TBL_NAME_PRODUCT_META_LOOKUP . ' SET `' . $field . '`=:value WHERE `product_id`=:product_id' );
				$stmt_update->bindParam( ':value', $value );
				$stmt_update->bindParam( ':product_id', $item_id );
				$stmt_update->execute();
			}
			
		}
		
	}
	
	/* 
	 * Connection DB functon
	 */
	private function _connect_db() {
		$dsn = 'mysql:host=' . $this->db_host . ';dbname=' . $this->db_name . ';charset=' . $this->db_charset;
    	$opt = [
        	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
     	   	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
   	    	PDO::ATTR_EMULATE_PREPARES   => false,
    	];
    	$this->pdo = new PDO($dsn, $this->db_user, $this->db_pass, $opt);
	}
}

class TempDB {
	private $db_path;
	private $create_tables = [
		'CREATE TABLE `data` ( `id` INT NOT NULL PRIMARY KEY, `model` VARCHAR(100) NULL DEFAULT NULL , `color` VARCHAR(100) NULL DEFAULT NULL , `price` FLOAT NULL DEFAULT NULL , `amount` INT(11) NULL DEFAULT NULL , `department` INT(11) NULL DEFAULT NULL );',
	];

	public $pdo;

	function __construct($path) {
		$this->db_path = $path;

		$this->_connect_db();
		$this->_create_tables();
	}

	function __destruct() {
		$this->pdo = null;
	}

	public function add_data( $data ) {
		foreach( $data as $id => $value ) {
			if( $value->model == '' ) {continue;}
			if( $value->model == '-' ) {continue;}
			if( $value->color == '' ) {continue;}
			$stmt = $this->pdo->prepare( 'INSERT INTO `data` ( `id`, `model`, `color`, `price`, `amount`, `department` ) VALUES( :id, :model, :color, :price, :amount, :department )' );
			$stmt->bindValue( ':id' , $id );
			$stmt->bindValue( ':model' , $value->model );
			$stmt->bindValue( ':color' , $value->color );
			$stmt->bindValue( ':price' , $value->price );
			$stmt->bindValue( ':amount' , $value->amount );
			$stmt->bindValue( ':department' , $value->department );
			$stmt->execute();
		}
	}

	public function get_all() {
		$stmt = $this->pdo->prepare('SELECT * FROM `data`');
		$stmt->execute();
		while( $result = $stmt->fetch(PDO::FETCH_LAZY) ) {
			print_r( $result );
		}
	}
	
	/* 
	 * Connection DB functon
	 */
	private function _connect_db() {
		$dsn = 'sqlite:' . $this->db_path;
    	$opt = [
        	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
     	   	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
   	    	PDO::ATTR_EMULATE_PREPARES   => false,
    	];
    	$this->pdo = new PDO($dsn, null, null, $opt);
	}

	private function _create_tables() {
		foreach( $this->create_tables as $query ) {
			$this->pdo->exec($query);
		}
	}
}


class ITigrisAPI {
	private $api_url;
	private $api_appname;
	private $api_key;

	public $product_categories = array(
		"accessories",
		"contactlenses",
		"glasses",
		"lenses",
		"sunglasses",
	);

	function __construct($url, $appname, $key, $get_all = true) {
		$this->api_appname 	= $appname;
		$this->api_key		= $key;

		$url = str_replace("%appname%", $this->api_appname, $url);
		$url = str_replace("%key%", $this->api_key, $url);
		$this->api_url = $url;

		if( $get_all ) {
			$this->get_all();
		}
	}

	/* 
	 * Get all items from CRM functon
	 * 
	 * @return array(
	 * 		model(sku) => array(
	 * 			color => color,
	 * 			price => price
	 * 		),
	 * );
	 */
	public function get_all() {
		$data = [];

		foreach( $this->product_categories as $cat ) {
			$result_data = [];
			$i2 = 1;
			do {
				$q = array(
					"product" => $cat,
					"page" => $i2,
				);
	
				$tmp_arr = $this->_query( $q );
				$result_data = array_merge( $result_data, $tmp_arr );

				$i2++;
			} while ( ! empty( $tmp_arr ) );

			$data = array_merge( $data, $result_data );
		}
		
		return $data;
	}

	private function _query( $data ) {
		$curl = curl_init();

		curl_setopt_array( $curl, [
			CURLOPT_URL => $this->api_url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_POSTFIELDS => json_encode( $data ),
			CURLOPT_HTTPHEADER => [
				"Content-Type: application/json"
			],
		] );

		$response = curl_exec( $curl );
		$err = curl_error( $curl );

		curl_close( $curl );

		if( $err ) {
			echo "cURL Error #:" . $err;
			return false;
		}

		$result = json_decode( $response );
		return $result;
	}
}



$Updater = new Updater();
$Updater->start();



?>