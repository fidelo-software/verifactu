<?php

namespace FideloSoftware\Verifactu;

class Result
{
	public function __construct(public ?string $response = null, public ?string $request = null, public int $status = 0, public ?string $error = null)
	{

	}

	/**
	 * @return array
	 */
    public function parseResponse(): array
    {
        $doc = new \DOMDocument();
        $doc->loadXML($this->response);

        $xpath = new \DOMXPath($doc);

        // Register namespaces used in your XML response
        $xpath->registerNamespace('env',  'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('tikR', 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/RespuestaSuministro.xsd');
        $xpath->registerNamespace('tik',  'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd');

        $result = [];

        // Cabecera
        $headerNode = $xpath->query('//tikR:Cabecera')->item(0);
        if ($headerNode) {
            $result['header'] = Helper::xmlNodeToArray($headerNode);
        }

        // Tiempo de espera
        $waitNode = $xpath->query('//tikR:TiempoEsperaEnvio')->item(0);
        if ($waitNode) {
            $result['waitTime'] = $waitNode->nodeValue;
        }

        // EstadoEnvio
        $statusNode = $xpath->query('//tikR:EstadoEnvio')->item(0);
        if ($statusNode) {
            $result['submissionStatus'] = $statusNode->nodeValue;
        }

        // DatosPresentacion (if present)
        $dataNode = $xpath->query('//tikR:DatosPresentacion')->item(0);
        if ($dataNode) {
            $result['submissionData'] = Helper::xmlNodeToArray($dataNode);
        }

        // Response lines
        $result['responseLines'] = [];

        foreach ($xpath->query('//tikR:RespuestaLinea') as $lineNode) {
            $lineXml = Helper::xmlNodeToArray($lineNode);

            // If description missing, fill from error catalog
            if (
                empty($lineXml['DescripcionErrorRegistro']) &&
                !empty($lineXml['CodigoErrorRegistro'])
            ) {
                $code = $lineXml['CodigoErrorRegistro'];
                $lineXml['DescripcionErrorRegistro'] =
                    Error::$errorCodes[$code] ?? 'Unknown error';
            }

            // Interpret EstadoRegistro
            $accepted = match ($lineXml['EstadoRegistro'] ?? null) {
                'Aceptado', 'AceptadoConErrores' => true,
                default => false,
            };

            $result['responseLines'][] = [
                'xml'      => $lineXml,
                'accepted' => $accepted
            ];
        }

        return $result;
    }
}
