<?php
/**
* En el mismo directorio que este script:
*probando dejar un comment desde android creo que sepuede sin problemas.
*   urls.txt     Lista de URL a scrapear, una URL por línea
*   datos.csv    Datos para la creación de entradas/páginas. Una línea por entrada/página formada por 15 campos separados por comas.
*
*/

$ARCHIVO_DATOS = 'datos.csv';
$ARCHIVO_URLS  = 'urls.txt';
// OJO ACÁ: si no anda, actualizar esta cookie (hasta que encuentre cómo hacerlo automático)
$COOKIES       = 'MoodleSession=bfa3007267037dd3feab129784cc97e5; MoodleSessionTest=5cUWKZcnlj; MOODLEID_=%25ED%25C3%251CC%25B7d; __switchTo5x=48; __unam=9bacdfc-1314fa19998-3c34663a-4; __utma=222027463.1364257426.1311301016.1311301016.1311301016.1; __utmb=222027463.2.10.1311301016; __utmc=222027463; __utmz=222027463.1311301016.1.1.utmcsr=(direct)|utmccn=(direct)|utmcmd=(none)';

// incluimos el parser
include('simple_html_dom.php');

// disguises the curl using fake headers and a fake user agent.
function disguise_curl($url){
	global $COOKIES;
	$curl = curl_init();
	// Setup headers - I used the same headers from Firefox version 2.0.0.6
	// below was split up because php.net said the line was too long. :/
	$header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
	$header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
	$header[] = "Cache-Control: max-age=0";
	$header[] = "Connection: keep-alive";
	$header[] = "Keep-Alive: 300";
	$header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
	$header[] = "Accept-Language: en-us,en;q=0.5";
	$header[] = "Pragma: "; // browsers keep this blank.
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
	curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
	curl_setopt($curl, CURLOPT_REFERER, 'http://www.google.com');
	curl_setopt($curl, CURLOPT_COOKIE, $COOKIES );
	curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate');
	curl_setopt($curl, CURLOPT_AUTOREFERER, true);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_TIMEOUT, 25);

	set_time_limit(30);	// Reseteamos el timeout de PHP por las dudas para darle 30 segundos de changüí
	$html = curl_exec($curl); // execute the curl command
	curl_close($curl); // close the connection

	return $html; // and finally, return $html
}

function escrapear(){
	global $ARCHIVO_DATOS;
	global $ARCHIVO_URLS;

	// Leemos el archivo de URL a escrapear
	$gestor = @fopen($ARCHIVO_URLS, "r");
	if ($gestor) {
		while (($buffer = fgets($gestor, 4096)) !== false) {
			$docsUrl[] = $buffer;
		}
		if (!feof($gestor)) {
			echo "Error: fallo inesperado de fgets()\n";
		}
		fclose($gestor);
	}

	// Para cada URL, nos conectamos, traemos la URL del iframe, nos conectamos al iframe y traemos lo que queramos
	$contenido = "";
	$i = 0;
	echo '<table class="informe"><thead><th>#</th><th>URL</th><th>Título</th><th>Advertencias</th></thead>';
	foreach($docsUrl as $url){
		$advertencias = "";
		echo '<tr><td>'.++$i.'</td><td><a href="' . $url . '">' . $url . '</a></td>';
		$html = disguise_curl($url);
		$html = str_get_html($html);

		$iframe = $html->find("iframe#embeddedhtml");
		$iframeUrl = $iframe[0]->attr[src];

		$html = disguise_curl($iframeUrl);
		$html = str_get_html($html);

		$titulo = trim(addslashes($html->find(".Section1 h1", 0)->innertext));

		$creditos = $html->find("table.creditos code");
		unset($docCreditos);
		foreach($creditos as $credito){
			$credito->outertext = '<p>' . $credito->innertext . '</p>';
			$docCreditos[] = $credito->outertext;
		}
		
		// adaptación de la ruta de la imagen destacada (revisar!)
		$creditosImg = $html->find("table.creditos img", 0);
		$iframeUrl = str_replace ("index.htm" , "", $iframeUrl);
		$creditosImg->src = $iframeUrl . $creditosImg->src;
		
		// nuestro propio código de créditos
		$divCreditos  = '<div class="creditos">';
		$divCreditos .= $creditosImg;
		foreach($docCreditos as $credito){
			$divCreditos .= $credito;
		}
		$divCreditos .= '</div>';

		$html->find("table.creditos", 0)->outertext = ''; // quitar tabla de créditos
		$html->find(".Section1 h1", 0)->outertext = '';   // quitar título del cuerpo

		$content = $divCreditos . $html->find(".Section1", 0)->innertext;
		$content = addslashes($content);

		$secciones = $html->find('h2');
		foreach($secciones as $seccion){
			if(strpos($seccion->innertext, 'Introducción a las actividades') !== false) {

				$excerpt = addslashes($seccion->next_sibling()->innertext);
			}
		}
		
		// mostramos una advertencia si en el documento hay ENLACES INTERNOS (!)
		$links = 0;
		foreach($html->find("a") as $link){
			if (strpos($link->href, "http://")=== FALSE){
				$links += 1;				
			};
		}
		if($links){ $advertencias .= '<span class="nota link">link ('.$links.')</span>'; }


		// mostramos una advertencia si en el documento hay TABLE (más allá de la de créditos)
		if($html->find("table",1)){
			$advertencias .= '<span class="nota tablas">tabla</span>';
		}
		// mostramos una advertencia si en el documento hay CITE
		if($html->find("cite",0)){
			$advertencias .= '<span class="nota cite">cite</span>';
		}
		// mostramos una advertencia si en el documento hay IMG
		if($html->find("img",1)){
			$advertencias .= '<span class="nota img">img</span>';
		}
		// mostramos una advertencia si en el documento hay EMBED
		if($html->find("embed",0)){
			$advertencias .= '<span class="nota embed">embed</span>';
		}
		// mostramos una advertencia si en el documento hay 0BJECTS
		if($html->find("object",0)){
			$advertencias .= '<span class="nota object">object</span>';
		}

		//deben ser 15 campos, marcados por comillas dobles (escapando las comillas internas)
		//título, "2009-08-08 08:08:08", contenido, excerpt, "", none, default, publish, post, open, closed, "", "", "", ""
		$contenido .= '"' . $titulo . '", "2009-08-08 08:08:08", "' . $content . '", "' . $excerpt . '", "Sin categoría", "0", "Default", "publish", "post", "open", "closed", "", "", "", ""'."\n";
		echo '<td>' . $titulo . '</td><td>' . $advertencias . '</td></tr>';
	}
	echo '</table>';

	// Escribimos los datos capturados en el archivo

	// Primero vamos a asegurarnos de que el archivo existe y es escribible.
	if (is_writable($ARCHIVO_DATOS)) {

		// En nuestro ejemplo estamos abriendo $ARCHIVO_DATOS en modo de adición (append).
		// El puntero al archivo está al final del archivo
		// donde irá $contenido cuando usemos fwrite() sobre él.
		if (!$gestor = fopen($ARCHIVO_DATOS, 'a')) {
			 echo "No se puede abrir el archivo ($ARCHIVO_DATOS)";
			 exit;
		}

		// Escribir $contenido a nuestro archivo abierto.
		if (fwrite($gestor, $contenido) === FALSE) {
			echo "No se puede escribir en el archivo ($ARCHIVO_DATOS)";
			exit;
		}

		echo '<p class="msg">Éxito, se escribió en el archivo <em><a href="' . $ARCHIVO_DATOS . '">' . $ARCHIVO_DATOS . '</a></em></p>';

		fclose($gestor);

	} else {
		echo '<p class="msg">El archivo ' . $ARCHIVO_DATOS . ' no es escribible';
	}
	return $writeFileMsg;
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr" lang="es" xml:lang="es">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>Scraperboy!</title>
		<link rel="shortcut icon" href="favicon.ico" />
		<link rel="stylesheet" type="text/css" href="style.css" />
	</head>
	<body>
		<?php escrapear(); ?>
	</body>
</html>