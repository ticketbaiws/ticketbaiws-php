<?php
/**
 * Cliente PHP para TicketBAI WS (ticketbaiws.eus)
 *
 * Cubre TicketBAI, Verifactu, BATUZ/LROE (Bizkaia), gestión de webhooks,
 * empresas y licencias contra la API de Ticketbai WS.
 *
 * Especificación de referencia: https://api.ticketbaiws.eus/openapi.json
 * (revisada contra info.version 1.0.3).
 *
 * Compatible con PHP 7.1+ (sin tipos en propiedades, sin match, etc.) y PHP 8.x.
 *
 * Uso básico:
 *   $tbai = new class_ticketbaiwsclient(class_ticketbaiwsclient::URL_PRUEBAS, $token, $nif);
 *   $r = $tbai->nuevo_tbai($factura);
 *   if($tbai->get_last_result()!=="ERROR"){ ... } else { $errores = $r; }
 *
 * -------------------------------------------------------------------------
 * Cambios v3 (openapi.json 1.0.5):
 *  - Factura electrónica B2B (SPFE): spfe_estados_alta(), spfe_estados_consulta()
 *    y spfe_recibidas_consulta().
 *  - webhooks_alta()/webhooks_modificar() admiten "eventos" (cambio_estado,
 *    spfe_envio, spfe_estado, spfe_recibida); empresas_alta()/empresas_editar()
 *    admiten "spfe" (bool).
 *  - FacturaE (facturae()) solo existe en producción.
 *
 * Cambios v2 (revisión contra openapi.json 1.0.3):
 *  - Nuevos métodos LROE 2.2 "gastos sin factura" (modelo 140, Bizkaia):
 *    lroe_gastos_sinfactura_alta/modificar/consulta/anular.
 *  - empresas_editar(): la ruta correcta es PUT /empresas/{nif}/ (el NIF va en
 *    la URL, no en el cuerpo). Se mantiene compatibilidad: si se llama como
 *    antes, empresas_editar($datos) con "nif" dentro de $datos, se extrae de ahí.
 *  - Empresas y licencias usan el TOKEN DE DESARROLLADOR y el NIF DE LA CUENTA
 *    (no los de una empresa). Nuevo set_credenciales_cuenta($token, $nif); si no
 *    se configuran, esos métodos siguen usando el token/NIF del constructor
 *    (comportamiento anterior).
 *  - HTTP 429 (límite de peticiones por NIF o IP): reintento automático con
 *    espera creciente (respeta Retry-After si llega). Configurable con
 *    set_reintentos_429(); 0 lo desactiva.
 *  - Constantes URL_PRODUCCION / URL_PRUEBAS y normalización de la URL base
 *    (con o sin barra final).
 *  - get_last_result(): devuelve OK / PENDING / ERROR de la última llamada, ya
 *    que procesarRespuesta() devuelve "return" o "msg" y no siempre se puede
 *    distinguir el éxito del error por el tipo (msg puede ser texto, XML,
 *    lista o null según la spec).
 *  - Si json_encode() falla (UTF-8 inválido, p.ej. datos en ISO-8859-1) antes
 *    se enviaba el cuerpo vacío sin avisar; ahora se devuelve un ERROR
 *    normalizado sin hacer la petición.
 *  - Verificación SSL activada por defecto (CURLOPT_SSL_VERIFYPEER + VERIFYHOST).
 *    set_ssl_verification(false) recupera el comportamiento anterior.
 *  - Las llamadas sin parámetros ya no envían "[]" como cuerpo.
 *  - curl_close() solo en PHP < 8 (desde PHP 8.0 no hace nada y en 8.5 está
 *    obsoleto).
 *
 * Cambios v1 (primera revisión):
 *  - Eliminada la redeclaración duplicada de nuevo_tbai() (error fatal).
 *  - callService() decodifica el JSON con cualquier status HTTP.
 *  - Fallos de red normalizados a {result:"ERROR", return:null, msg}.
 *  - $full_response y $last_http_status declaradas (PHP 8.2+) con getters.
 *  - Limpieza de código muerto en check_tbai()/xml_tbai().
 *  - doc_representante_enviar(): el campo multipart del fichero es "file".
 *  - webhooks_consulta(): admite filtros (activo, solo_errores).
 * -------------------------------------------------------------------------
 *
 * Notas de la spec a tener en cuenta:
 *  - La API responde HTTP 200 con {result, return, msg}; result vale OK,
 *    PENDING (Verifactu: aceptada y en cola) o ERROR. Decidir siempre por result.
 *  - Los importes de las líneas van SIN IVA.
 *  - Envío en segundo plano por defecto; "sincrono": true en nuevo_tbai() para
 *    esperar la respuesta de Hacienda.
 *  - Empresas, licencias y validación de NIF solo existen en producción.
 *  - Los webhooks son independientes por entorno; cada aviso trae la cabecera
 *    X-TbaiWs-Environment (test|real).
 */
class class_ticketbaiwsclient{

	const URL_PRODUCCION = "https://api.ticketbaiws.eus/";
	const URL_PRUEBAS    = "https://api-test.ticketbaiws.eus/";

	// Códigos de diputación / régimen usados en empresas_alta() y en la
	// cabecera de facturas (id_diputacion / diputacion).
	const DIPUTACION_ALAVA     = 1;
	const DIPUTACION_BIZKAIA   = 2;
	const DIPUTACION_GIPUZKOA  = 3;
	const DIPUTACION_VERIFACTU = 4;

	private $url;
	private $token;
	private $nif;
	private $token_cuenta;
	private $nif_cuenta;
	private $ssl_verifycation;
	private $debug;
	private $timeout;
	private $reintentos_429;
	private $full_response;
	private $last_http_status;
	private $last_result;
	private $retry_after;

	function __construct($url="",$token="",$nif=""){
		if($url!=""){
			$this->url = $url;
			$this->token = $token;
			$this->nif = $nif;
		}
		else{
			global $tbaiws_url,$tbaiws_token,$tbaiws_nif;
			$this->url = $tbaiws_url;
			$this->token = $tbaiws_token;
			$this->nif = $tbaiws_nif;
		}
		$this->url = rtrim((string)$this->url, "/")."/";
		$this->token_cuenta = null;
		$this->nif_cuenta = null;
		$this->ssl_verifycation = true;
		$this->debug = false;
		$this->timeout = 60;
		$this->reintentos_429 = 3;
		$this->full_response = null;
		$this->last_http_status = null;
		$this->last_result = null;
		$this->retry_after = null;
	}

	// -----------------------------------------------------------------
	// Configuración del cliente
	// -----------------------------------------------------------------

	function set_ssl_verification($verificar){
		$this->ssl_verifycation = (bool)$verificar;
	}

	function set_debug($debug){
		$this->debug = (bool)$debug;
	}

	function set_timeout($segundos){
		$this->timeout = (int)$segundos;
	}

	/** Número de reintentos ante HTTP 429 (por defecto 3; 0 = sin reintentos). */
	function set_reintentos_429($reintentos){
		$this->reintentos_429 = max(0, (int)$reintentos);
	}

	/**
	 * Credenciales de la cuenta de cliente (token de desarrollador del área
	 * privada + NIF de la cuenta). Las usan los métodos de empresas y licencias.
	 */
	function set_credenciales_cuenta($token_desarrollador, $nif_cuenta){
		$this->token_cuenta = $token_desarrollador;
		$this->nif_cuenta = $nif_cuenta;
	}

	/** Devuelve la última respuesta completa (decodificada si era JSON), útil para depurar. */
	function get_full_response(){
		return $this->full_response;
	}

	/** Código HTTP de la última llamada realizada. */
	function get_last_http_status(){
		return $this->last_http_status;
	}

	/** "OK", "PENDING" o "ERROR" de la última llamada (ERROR también si la respuesta no se pudo interpretar). */
	function get_last_result(){
		return $this->last_result;
	}

	// -----------------------------------------------------------------
	// Generales
	// -----------------------------------------------------------------

	function check_status(){
		$respuesta = $this->callService("status","GET");
		return(isset($respuesta["result"]) && $respuesta["result"]=="OK");
	}

	/** POST /validar-nif/ — $datos: nif (req), nombre (req solo personas físicas). Solo producción. */
	function validar_nif($datos){
		return $this->procesarRespuesta($this->callService("validar-nif","POST",$datos));
	}

	/** POST /validar-nif-vies/ — $datos: nif (req), pais (req, ISO-3166 2 letras). Solo producción. */
	function validar_nif_vies($datos){
		return $this->procesarRespuesta($this->callService("validar-nif-vies","POST",$datos));
	}

	// -----------------------------------------------------------------
	// TicketBAI / Verifactu — factura
	// -----------------------------------------------------------------

	/**
	 * POST /tbai/ (alias /verifactu/). Importes de línea SIN IVA.
	 * Para subsanar: mismo envío con "zuzendu" => true (serie, número y total iguales).
	 * "sincrono" => true para esperar la respuesta de Hacienda.
	 */
	function nuevo_tbai($info_factura){
		return $this->procesarRespuesta($this->callService("tbai","POST",$info_factura));
	}

	/** GET /tbai-list/ — $datos: fecha_inicio, fecha_fin, serie, pagina, json_orig, xml_request, pedido. */
	function tbai_list($datos){
		return $this->procesarRespuesta($this->callService("tbai-list","GET",$datos));
	}

	function tbai_completar($info_factura){
		return $this->procesarRespuesta($this->callService("tbai-completar","POST",$info_factura));
	}

	/** DELETE /tbai/ — $info_factura: serie, numero y opcionalmente fecha (dd/mm/yyyy; si no, la última emitida). */
	function anula_tbai($info_factura){
		return $this->procesarRespuesta($this->callService("tbai","DELETE",$info_factura));
	}

	/** Devuelve la respuesta cruda (result/return/msg) de la consulta, sin desempaquetar. */
	function check_tbai($info_factura){
		return $this->callService("tbai","GET",$info_factura);
	}

	/** Devuelve la respuesta cruda con xml_request/xml_response. */
	function xml_tbai($info_factura){
		return $this->callService("tbai-xml","GET",$info_factura);
	}

	/** GET /tbai-pdf/ — $info_factura: serie, numero. Devuelve el PDF en base64. */
	function tbai_pdf($info_factura){
		return $this->procesarRespuesta($this->callService("tbai-pdf","GET",$info_factura));
	}

	/**
	 * GET /facturae/ (solo producción) — $info_factura: serie, numero, y opcionalmente
	 * cod_organo_gestor, cod_unidad_tramitadora, cod_oficina_contable (FACe).
	 * Devuelve el XML FacturaE v3.2 en base64.
	 */
	function facturae($info_factura){
		return $this->procesarRespuesta($this->callService("facturae","GET",$info_factura));
	}

	/** PUT /reset-tbai/ — $info_factura: serie, numero. Fuerza el reenvío de una factura en PENDIENTE. */
	function reset_tbai($info_factura){
		return $this->procesarRespuesta($this->callService("reset-tbai","PUT",$info_factura));
	}

	// -----------------------------------------------------------------
	// Documento de representación (Verifactu, Bizkaia, Gipuzkoa; no Araba)
	// -----------------------------------------------------------------

	/**
	 * GET /doc-representante/modelo/ — $datos (solo personas jurídicas):
	 * nombre_representante, nif_representante, poblacion_representante,
	 * direccion_representante. Devuelve un PDF precargado en base64.
	 */
	function doc_representante_modelo($datos=[]){
		return $this->procesarRespuesta($this->callService("doc-representante/modelo","GET",$datos));
	}

	/**
	 * POST /doc-representante/ (multipart/form-data) — envía el documento de
	 * representación ya firmado. $ruta_fichero: ruta local al PDF firmado.
	 */
	function doc_representante_enviar($ruta_fichero, $datos=[], $campo_fichero="file"){
		if(!is_file($ruta_fichero)){
			$this->last_result = "ERROR";
			return "El fichero '".$ruta_fichero."' no existe o no es accesible";
		}
		return $this->procesarRespuesta($this->callServiceMultipart("doc-representante", $ruta_fichero, $datos, $campo_fichero));
	}

	/** GET /doc-representante/ — sin parámetros. Devuelve el PDF firmado en base64. */
	function doc_representante(){
		return $this->procesarRespuesta($this->callService("doc-representante","GET"));
	}

	/** DELETE /doc-representante/ — revoca el documento (solo antes de haber hecho envíos reales). */
	function doc_representante_revocar(){
		return $this->procesarRespuesta($this->callService("doc-representante","DELETE"));
	}

	// -----------------------------------------------------------------
	// BATUZ / LROE (Bizkaia)
	// -----------------------------------------------------------------

	/** GET /epigrafes/ — listado de epígrafes (codigo, nombre_es, nombre_eu). */
	function epigrafes(){
		return $this->procesarRespuesta($this->callService("epigrafes","GET"));
	}

	/** LROE facturas recibidas. Consulta: ejercicio (req) + filtros; anular: ejercicio + facturas[]. */
	function lroe_recibidas_alta($datos){
		return $this->procesarRespuesta($this->callService("lroe-recibidas","POST",$datos));
	}
	function lroe_recibidas_modificar($datos){
		return $this->procesarRespuesta($this->callService("lroe-recibidas","PUT",$datos));
	}
	function lroe_recibidas_consulta($datos){
		return $this->procesarRespuesta($this->callService("lroe-recibidas","GET",$datos));
	}
	function lroe_recibidas_anular($datos){
		return $this->procesarRespuesta($this->callService("lroe-recibidas","DELETE",$datos));
	}

	/**
	 * LROE 2.2 — gastos sin factura. Solo BATUZ Bizkaia, modelo 140 (personas
	 * físicas con actividad y entidades en atribución de rentas).
	 * Alta: ejercicio + gastos[] (máx. 1000) con fecha_operacion, concepto,
	 * epigrafe, linea, importe y opcionales nif/nombre_social, pais,
	 * tipo_documento, referencia_bien, referencia_catastral, territorio_alta_actividad...
	 * Los gastos ya aceptados no se reenvían (vuelven con "existe"); para
	 * cambiarlos, modificar. El gasto se identifica por fecha_operacion +
	 * epigrafe + concepto + linea (no modificables: anular y reenviar).
	 */
	function lroe_gastos_sinfactura_alta($datos){
		return $this->procesarRespuesta($this->callService("lroe-gastos-sinfactura","POST",$datos));
	}
	/** PUT — se deben enviar todos los datos del gasto. */
	function lroe_gastos_sinfactura_modificar($datos){
		return $this->procesarRespuesta($this->callService("lroe-gastos-sinfactura","PUT",$datos));
	}
	/** GET — ejercicio (req) + fecha_operacion_desde/hasta, nif, pais, tipo_documento, epigrafe, estado, pagina. */
	function lroe_gastos_sinfactura_consulta($datos){
		return $this->procesarRespuesta($this->callService("lroe-gastos-sinfactura","GET",$datos));
	}
	/** DELETE — ejercicio + gastos[] con los campos que identifican cada gasto. */
	function lroe_gastos_sinfactura_anular($datos){
		return $this->procesarRespuesta($this->callService("lroe-gastos-sinfactura","DELETE",$datos));
	}

	/** Régimen especial de criterio de caja — cobros. Solo BATUZ Bizkaia. */
	function lroe_critcaja_cobros_alta($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-cobros","POST",$datos));
	}
	function lroe_critcaja_cobros_modificar($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-cobros","PUT",$datos));
	}
	function lroe_critcaja_cobros_consulta($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-cobros","GET",$datos));
	}
	function lroe_critcaja_cobros_anular($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-cobros","DELETE",$datos));
	}

	/**
	 * Régimen especial de criterio de caja — pagos. Solo BATUZ Bizkaia.
	 * $datos: ejercicio + pagos[] con nombre_social, nif, fecha_factura,
	 * fecha_pago, serie, num_factura, importe_pagado, iva_soportado,
	 * iva_deducible, forma_pago, descripcion_fpago (y el resto de campos
	 * opcionales documentados para el caso "sin_factura").
	 */
	function lroe_critcaja_pagos_alta($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-pagos","POST",$datos));
	}
	function lroe_critcaja_pagos_modificar($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-pagos","PUT",$datos));
	}
	function lroe_critcaja_pagos_consulta($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-pagos","GET",$datos));
	}
	function lroe_critcaja_pagos_anular($datos){
		return $this->procesarRespuesta($this->callService("lroe-critcaja-pagos","DELETE",$datos));
	}

	// -----------------------------------------------------------------
	// Webhooks (independientes en pruebas y producción)
	// -----------------------------------------------------------------

	/** POST /webhooks/ — $datos: url (req), secret (req), solo_errores, activo, eventos (array). */
	function webhooks_alta($datos){
		return $this->procesarRespuesta($this->callService("webhooks","POST",$datos));
	}

	/** PUT /webhooks/{codigo_webhook}/ — $datos: url, secret, solo_errores, activo. */
	function webhooks_modificar($codigo_webhook, $datos){
		return $this->procesarRespuesta($this->callService("webhooks/".rawurlencode($codigo_webhook),"PUT",$datos));
	}

	/**
	 * GET /webhooks/ — $datos opcional: filtros solo_errores, activo (bool).
	 * Devuelve los webhooks de los dos entornos; "entorno" (test|real) indica
	 * a cuál pertenece cada uno.
	 */
	function webhooks_consulta($datos=[]){
		return $this->procesarRespuesta($this->callService("webhooks","GET",$datos));
	}

	/**
	 * Verifica la firma HMAC-SHA256 (cabecera X-Tbaiws-Hmac-Sha256) de una
	 * notificación entrante de webhook. $cuerpo_peticion debe ser el cuerpo
	 * RAW recibido (p.ej. file_get_contents("php://input")), sin decodificar.
	 */
	static function webhooks_verificar_firma($cuerpo_peticion, $secret, $firma_recibida){
		$firma_calculada = base64_encode(hash_hmac('sha256', $cuerpo_peticion, $secret, true));
		return hash_equals($firma_calculada, (string)$firma_recibida);
	}

	// -----------------------------------------------------------------
	// Factura electrónica B2B (Ley Crea y Crece, SPFE de la AEAT)
	// Solo para empresas con la SPFE activada.
	// -----------------------------------------------------------------

	/**
	 * POST /spfe-estados/ — comunica pago, cobro, rechazo o impago.
	 * $datos: estado (cobro|impago|pago|rechazo), serie+numero (emitidas) o
	 * factura+fecha_factura+nif_emisor+nombre_emisor (recibidas), fecha,
	 * vencimiento, motivo_rechazo, baja, sincrono...
	 */
	function spfe_estados_alta($datos){
		return $this->procesarRespuesta($this->callService("spfe-estados","POST",$datos));
	}

	/** GET /spfe-estados/ — $datos opcional: id, serie, numero, factura, nif_emisor. */
	function spfe_estados_consulta($datos=[]){
		return $this->procesarRespuesta($this->callService("spfe-estados","GET",$datos));
	}

	/**
	 * GET /spfe-recibidas/ — facturas recibidas de proveedores. $datos opcional:
	 * desde_id, id, estado (pendiente|pagada|rechazada), nif_emisor, factura,
	 * desde, hasta, limite, ubl.
	 */
	function spfe_recibidas_consulta($datos=[]){
		return $this->procesarRespuesta($this->callService("spfe-recibidas","GET",$datos));
	}

	// -----------------------------------------------------------------
	// Empresas / Licencias (credenciales de la cuenta, solo producción)
	// -----------------------------------------------------------------

	/** POST /empresas/ — alta de empresa. $datos incluye diputacion (ver constantes DIPUTACION_*). */
	function empresas_alta($datos){
		return $this->procesarRespuesta($this->callService("empresas","POST",$datos,true));
	}

	/**
	 * PUT /empresas/{nif}/ — editar empresa existente.
	 * Firma nueva: empresas_editar($nif, $datos).
	 * Firma antigua (compatible): empresas_editar($datos) con "nif" dentro de $datos.
	 * Campos: nombre_social, nombre_comercial, direccion, poblacion, provincia,
	 * cp, email, web, epigrafe, autorenovacion.
	 */
	function empresas_editar($nif, $datos=null){
		if(is_array($nif) && $datos===null){
			$datos = $nif;
			$nif = isset($datos["nif"]) ? $datos["nif"] : "";
			unset($datos["nif"]);
		}
		if((string)$nif===""){
			$this->last_result = "ERROR";
			return "Falta el NIF de la empresa a editar";
		}
		return $this->procesarRespuesta($this->callService("empresas/".rawurlencode($nif),"PUT",$datos,true));
	}

	/** GET /empresas/ — $datos opcional: id_licencia, nif. */
	function empresas_listado($datos=[]){
		return $this->procesarRespuesta($this->callService("empresas","GET",$datos,true));
	}

	/** POST /licencias/ — $datos: plan, cantidad, meses_anos, modalidad (mensual|anual). */
	function licencias_alta($datos){
		return $this->procesarRespuesta($this->callService("licencias","POST",$datos,true));
	}

	/** GET /licencias/ — $id_licencia opcional; si se omite devuelve todas. */
	function licencias_listado($id_licencia=null){
		$datos = ($id_licencia!==null) ? ["id_licencia"=>$id_licencia] : [];
		return $this->procesarRespuesta($this->callService("licencias","GET",$datos,true));
	}

	// -----------------------------------------------------------------
	// Internos
	// -----------------------------------------------------------------

	/**
	 * Desempaqueta el sobre estándar {result, return, msg}: si la petición
	 * falló (o la respuesta no se pudo interpretar) devuelve el mensaje de
	 * error (o la respuesta cruda si no hay "msg"); si fue bien (OK o
	 * PENDING), devuelve únicamente el contenido de "return".
	 * Usar get_last_result() para saber si fue error.
	 */
	private function procesarRespuesta($respuesta){
		if(!is_array($respuesta) || !isset($respuesta["result"]) || $respuesta["result"]=="ERROR"){
			if(is_array($respuesta) && array_key_exists("msg",$respuesta)){
				return $respuesta["msg"];
			}
			return $respuesta;
		}
		return isset($respuesta["return"]) ? $respuesta["return"] : null;
	}

	/** Cabeceras de autenticación: de empresa, o de cuenta si $cuenta y están configuradas. */
	private function cabecerasAuth($cuenta){
		if($cuenta && $this->token_cuenta!==null){
			return array("Token: ".$this->token_cuenta, "Nif: ".$this->nif_cuenta);
		}
		return array("Token: ".$this->token, "Nif: ".$this->nif);
	}

	private function callService($serviceName, $method, $parameters = [], $cuenta = false) {
		$request = "";
		if(!empty($parameters)){
			$request = json_encode($parameters);
			if($request===false){
				return $this->respuestaError("Error al codificar el JSON de la petición: ".json_last_error_msg());
			}
		}
		$serviceUrl = $this->url.$serviceName."/";

		$headers = array_merge(array(
					"Content-Type: application/json",
					"Accept: application/json;charset=UTF-8"
				), $this->cabecerasAuth($cuenta));

		$intento = 0;
		while(true){
			$ch = curl_init();

			switch ($method){
			  case "POST":
					curl_setopt($ch, CURLOPT_POST, true);
					curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
				 break;
			  case "PUT":
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
					curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
				 break;
			  case "GET":
					// La API acepta los parámetros de GET/DELETE como JSON en el cuerpo
					// (además de query string); se mantiene el cuerpo porque admite
					// estructuras anidadas (p.ej. facturas[] en los DELETE del LROE).
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
					if($request!==""){
						curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
					}
				 break;
			  case "DEL":
			  case "DELETE":
					curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
					if($request!==""){
						curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
					}
				 break;
			}

			curl_setopt($ch, CURLOPT_URL, $serviceUrl);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$this->opcionesComunes($ch);

			$streamVerboseHandle = null;
			if($this->debug){
				curl_setopt($ch, CURLOPT_VERBOSE, true);
				$streamVerboseHandle = fopen('php://temp', 'w+');
				curl_setopt($ch, CURLOPT_STDERR, $streamVerboseHandle);
			}

			$response = curl_exec($ch);

			if($this->debug){
				echo $serviceUrl."\n";
				echo $method."\n";
				var_export($headers);
				echo "\n";
				var_export($request);
				echo "\n";
				var_export($response);
				echo "\n\n\n";

				if ($response === FALSE) {
					printf("cUrl error (#%d): %s<br>\n",
					curl_errno($ch),
					htmlspecialchars(curl_error($ch)));
				}

				rewind($streamVerboseHandle);
				$verboseLog = stream_get_contents($streamVerboseHandle);

				echo "cUrl verbose information:\n",
				"<pre>", htmlspecialchars($verboseLog), "</pre>\n";

			  exit;
			}

			$resultado = $this->procesarCurl($ch, $response);
			if($this->last_http_status==429 && $intento < $this->reintentos_429){
				$this->esperarReintento($intento);
				$intento++;
				continue;
			}
			return $resultado;
		}
	}

	/**
	 * multipart/form-data (subida de fichero). No se envía cabecera
	 * Content-Type manual: curl la genera con el boundary correcto al pasar
	 * un array (con al menos un CURLFile) como CURLOPT_POSTFIELDS.
	 */
	private function callServiceMultipart($serviceName, $ruta_fichero, $parameters = [], $campo_fichero = "file") {
		$serviceUrl = $this->url.$serviceName."/";

		$postFields = is_array($parameters) ? $parameters : [];
		$postFields[$campo_fichero] = new CURLFile($ruta_fichero, "application/pdf", basename($ruta_fichero));

		$headers = array_merge(array(
					"Accept: application/json;charset=UTF-8"
				), $this->cabecerasAuth(false));

		$intento = 0;
		while(true){
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $serviceUrl);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$this->opcionesComunes($ch);

			$response = curl_exec($ch);

			$resultado = $this->procesarCurl($ch, $response);
			if($this->last_http_status==429 && $intento < $this->reintentos_429){
				$this->esperarReintento($intento);
				$intento++;
				continue;
			}
			return $resultado;
		}
	}

	private function opcionesComunes($ch){
		$this->retry_after = null;
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->ssl_verifycation);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->ssl_verifycation ? 2 : 0);
		$self = $this;
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $linea) use ($self){
			if(stripos($linea, "Retry-After:")===0){
				$self->setRetryAfter(trim(substr($linea, 12)));
			}
			return strlen($linea);
		});
	}

	/** @internal Solo para el callback de cabeceras de curl. */
	function setRetryAfter($valor){
		$this->retry_after = ctype_digit($valor) ? (int)$valor : null;
	}

	/** Espera antes de reintentar un 429: Retry-After si llega (máx. 60 s); si no, 1, 2, 4... s. */
	private function esperarReintento($intento){
		$espera = ($this->retry_after!==null) ? $this->retry_after : pow(2, $intento);
		sleep(max(1, min(60, (int)$espera)));
	}

	private function respuestaError($msg){
		$response = array("result" => "ERROR", "return" => null, "msg" => $msg);
		$this->last_http_status = null;
		$this->last_result = "ERROR";
		$this->full_response = $response;
		return $response;
	}

	/**
	 * Lógica común tras curl_exec(): registra errores de red de forma
	 * normalizada (en vez de propagar `false`), decodifica JSON cuando el
	 * cuerpo es JSON válido (independientemente del status HTTP, ya que los
	 * errores de negocio también vienen como {result:"ERROR",msg:...}), y
	 * deja $full_response / $last_http_status / $last_result listos.
	 */
	private function procesarCurl($ch, $response){
		$network_err = curl_errno($ch);
		$curlError = $network_err ? curl_error($ch) : null;
		$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if(PHP_VERSION_ID < 80000){
			curl_close($ch);
		}

		if($network_err){
			error_log('curl_err: '.$network_err.' - '.$curlError);
			$response = array(
				"result" => "ERROR",
				"return" => null,
				"msg"    => "Error de conexion (curl #".$network_err."): ".$curlError
			);
		}
		else{
			$response_decoded = json_decode($response, true);
			if($response_decoded !== null || $response==="null"){
				$response = $response_decoded;
			}
			elseif($httpStatus==429){
				$response = array("result"=>"ERROR","return"=>null,"msg"=>"Límite de peticiones superado (HTTP 429)");
			}
			elseif($httpStatus==404){
				$response = array("result"=>"ERROR","return"=>null,"msg"=>"Recurso o método HTTP no disponible (HTTP 404)");
			}
			// si no decodifica (HTML, texto plano, cuerpo vacio...) se deja $response tal cual
		}

		$this->last_http_status = $httpStatus;
		$this->last_result = (is_array($response) && isset($response["result"]) && in_array($response["result"], array("OK","PENDING"), true))
			? $response["result"] : "ERROR";
		$this->full_response = $response;
		return $response;
	}
}
