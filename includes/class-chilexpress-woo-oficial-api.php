<?php 

if ( ! class_exists( 'Chilexpress_Woo_Oficial_API' ) ) {
	class Chilexpress_Woo_Oficial_API {

		const RESPONSE_KEY = 'response';
		const CODE_KEY = 'code';
		/**
		 * Constructor for your shipping class 
		 *
		 * @access public
		 * @return void
		 */
		public function __construct() {
			$this->id                 = 'chilexpress_woo_oficial';
			$this->init();

			$this->api_staging_base_url = 'https://testservices.wschilexpress.com/';
			$this->api_production_base_url = 'https://services.wschilexpress.com/';

			$module_options = get_option( 'chilexpress_woo_oficial' );
			if ($module_options['ambiente'] == 'production') {
				$this->api_base_url = $this->api_production_base_url;
			} else {
				$this->api_base_url = $this->api_staging_base_url;
			} 

			$this->api_key_georeferencia = isset($module_options['api_key_cotizador_value'])? $module_options['api_key_cotizador_value']:'';
			$this->api_key_cobertura = isset($module_options['api_key_georeferencia_value'])? $module_options['api_key_georeferencia_value']:'';
			$this->api_key_ot = isset($module_options['api_key_generacion_ot_value'])? $module_options['api_key_generacion_ot_value']:'';

			$this->api_geo_enabled = isset($module_options['api_key_georeferencia_enabled'])? $module_options['api_key_georeferencia_enabled'] : false;
			$this->api_ot_enabled = isset($module_options['api_key_generacion_ot_enabled'])? $module_options['api_key_generacion_ot_enabled'] : false;
		}

		public function init(){
			// Init is required by wordpress
		}

		public function validar_dia_habil() {
			
			date_default_timezone_set("America/Santiago");
			$date   = new DateTime();
			$fecha_actual = $date->format('Y-m-d');
			$url = 'https://services.wschilexpress.com/agendadigital/api/v4/Utilitarios/DiaHabil?fecha='.$fecha_actual;
			
			$response = $this->do_remote_get($url, '9c853753ce314c81934c4f966dad7755');
			if ( is_wp_error( $response ) ) {
				return $response;
			} else if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
				$data = json_decode($response['body']);
				$respuesta = array();
				foreach ($data->DiaHabil as $entry) {
					$respuesta = array("codigo"=>$entry->IndHabil, "glosa"=>$entry->GlsHabil);
				}
				return $respuesta;
			}
		}

		public function obtener_descripcion_articulos() {
			$url = 'https://services.wschilexpress.com/agendadigital/api/v3/Cotizador/GetArticulos';
			
			$response = $this->do_remote_get($url, '9c853753ce314c81934c4f966dad7755');
			if ( is_wp_error( $response ) ) {
				return $response;
			} else if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
				$data = json_decode($response['body']);
				$descripciones = array();
				foreach ($data->ListArticulos as $entry) {
					$descripciones[] = array("value"=>$entry->Codigo, "label"=>$entry->Glosa);
				}
				return $descripciones;
			}
		}

		public function obtener_regiones() {
			$url = $this->api_base_url . 'georeference/api/v1.0/regions';
			$response = $this->do_remote_get($url, $this->api_key_cobertura);
			if ( is_wp_error( $response ) || !$response ) {
				return $response;
			} else if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
				$data = json_decode($response['body']);
				$regiones = array();
				foreach ($data->regions as $region) {
					$regiones[$region->regionId] = $region->regionName;
				}
				return $regiones;
			}
		}

		public function obtener_comunas_desde_region($codigo_region = "R1") {
			$url = $this->api_base_url . 'georeference/api/v1.0/coverage-areas?RegionCode='.$codigo_region.'&type=1';

			$response = $this->do_remote_get($url, $this->api_key_cobertura);

			if ( is_wp_error( $response ) ) {
				return $response;

			} else if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
				$data = json_decode($response['body']);
				$comunas = array();

				foreach ($data->coverageAreas as $comuna) {
					$comunas[$comuna->countyCode] = $comuna->coverageName;
				}

				unset($comunas["SCOB"]); // eliminar comuna "Sin Cobertura"

				return $comunas;
			}
		}

		public function obtener_cotizacion($comuna_origen, $comuna_destino, $weight = 1, $height = 1, $width = 1, $length = 1, $declaredWorth = 1000, $deliveryTime, $tccOrigen) { // NOSONAR
			
			$payload = array(
				"originCountyCode" =>	$comuna_origen,
				"destinationCountyCode" => $comuna_destino,
				"package" => array(
					"weight" =>	$weight,
					"height" =>	$height,
					"width" =>	$width,
					"length" =>	$length
				),
				"productType" => 3,
				"contentType" => 1,
				"declaredWorth" => $declaredWorth,
				"deliveryTime" => $deliveryTime,
				"customerCardNumber" => $tccOrigen
			);

			$url = $this->api_base_url."rating/api/v1.0/rates/business";
			$response = $this->do_remote_post($url,$this->api_key_georeferencia, $payload);
			
			if ( is_wp_error( $response ) ) {
				return $response;
			} else {
			   	if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
			   		$json_response = json_decode($response['body']);

					return $json_response->data->courierServiceOptions;
				} else if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 400) {
					$json_response = json_decode($response['body']);
					return new WP_Error("chilexpress-woo-oficial","$json_response->statusDescription");
			    } else {
					return new WP_Error("chilexpress-woo-oficial","Invalid Request");
				}
			}
			
		}

		public function generar_ot($payload) {

			$url = $this->api_base_url."transport-orders/api/v1.0/transport-orders";
			$response = $this->do_remote_post($url,$this->api_key_ot, $payload);	

			if ( is_wp_error( $response ) ) {
				return $response;
			} else {
			   	if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
			   		return json_decode($response['body']);
			    }
			}			
		}

		public function cerrar_certificado($payload) {

			$url = $this->api_base_url."transport-orders/api/v1.0/transport-order-certificates";
			$response = $this->do_remote_put($url, $this->api_key_ot, $payload );

			if ( is_wp_error( $response ) ) {

				$result = $response;

			} else {

				if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
			   		
			   		$result = json_decode($response['body']);
					return $result;

				} else if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 400) {
					
					$json_response = json_decode($response['body']);
					$result = new WP_Error("chilexpress-woo-oficial","$json_response->statusDescription");

			    } else {

					$result = new WP_Error("chilexpress-woo-oficial","Invalid Request");

				}
			}
			return $result;
		}

		public function obtener_estado_ot($trackingId, $reference, $rut ) {
			$payload = array(
				"reference"=> $reference,
				"transportOrderNumber"=> $trackingId,
				"rut"=> $rut,
				"showTrackingEvents" => 1
			);
			$url = $this->api_base_url."transport-orders/api/v1.0/tracking";
			$response = $this->do_remote_post($url,$this->api_key_ot, $payload);

			if ( is_wp_error( $response ) ) {
				$result = $response;
			} else {
			   	if ($response[self::RESPONSE_KEY][self::CODE_KEY] == 200) {
					$result = json_decode($response['body']);
			    }
				else{
					$body = json_decode($response['body']);
					$result = new WP_Error( $response[self::RESPONSE_KEY][self::CODE_KEY], '[ '.$body->statusCode.' ] '.$body->statusDescription );
				}
			}
			return $result;
		}

		private function do_remote_put($url, $api_key, $payload){
			return wp_remote_post( $url, array(
				'method' => 'PUT',
				'timeout' => 90,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array(
					'Content-Type' => 'application/json',
	    			'Ocp-Apim-Subscription-Key' => $api_key
				),
				'body' => json_encode($payload),
				'cookies' => array(),
				'sslverify' => FALSE
			    )
			);
		}

		private function do_remote_post($url, $api_key, $payload){
			return wp_remote_post( $url, array(
				'method' => 'POST',
				'timeout' => 90,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array(
					'Content-Type' => 'application/json',
	    			'Ocp-Apim-Subscription-Key' => $api_key
				),
				'body' => json_encode($payload),
				'cookies' => array(),
				'sslverify' => FALSE
			    )
			);
		}

		private function do_remote_get($url, $api_key)
		{
			return wp_remote_post( $url, array(
				'method' => 'GET',
				'timeout' => 90,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array(
					'Content-Type' => 'application/json',
	    			'Ocp-Apim-Subscription-Key' => $api_key
				),
				'body' => '',
				'cookies' => array(),
				'sslverify' => FALSE
			    )
			);
		}
		
	}
}
