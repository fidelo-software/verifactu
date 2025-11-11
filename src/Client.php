<?php

namespace FideloSoftware\Verifactu;

use Exception;

class Client
{

	//private ?string $wsdl = __DIR__ . '/Xsd/SistemaFacturacion.wsdl';
    private ?string $wsdl ='https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';

	private ?string $location = null;

	private ?Certificate $certificate = null;

	private bool $test = false;

	public function setTest(bool $test): Client
	{
		$this->test = $test;
		return $this;
	}

	public function setWSDL(?string $wsdl): Client
	{
		$this->wsdl = $wsdl;
		return $this;
	}

	public function setLocation(?string $location): Client
	{
		$this->location = $location;
		return $this;
	}

	public function setCertificate(?Certificate $certificate): Client
	{
		$this->certificate = $certificate;
		return $this;
	}

	private function getLocation($operation): string
	{
		if (!empty($this->location)) return $this->location;
		return $this->getLocationFromWSDL($operation);
	}

	public function callWithSoapClient(string $operation, array $data): Result
	{
		$result = new Result();
		if (!extension_loaded('soap')) {
			$result->error = 'SOAP extension not loaded.';
			return $result;
		}

		$location = trim($this->getLocation($operation));

		$options = [
			'trace' => true,
			'exceptions' => true,
			'cache_wsdl' => WSDL_CACHE_NONE,
			'stream_context' => stream_context_create([
				'ssl' => [
					'verify_peer' => true,
					'verify_peer_name' => true,
					'allow_self_signed' => true,
					'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
				],
			]),
			'soap_version' => SOAP_1_1,
			'style' => SOAP_DOCUMENT,
			'use' => SOAP_LITERAL,
			'local_cert' => $this->certificate->getCertificatePath(),
			'passphrase' => $this->certificate->getCertificatePassphrase()
		];

		try {
			$soapClient = new SoapClient($this->wsdl, $options);
		} catch (Exception $e) {
			$result->error = 'SOAP Error: '.$e->getMessage();
			return $result;
		}

		try {
			$soapClient->__setLocation($location);
			$soapClient->__soapCall($operation, [$data]);
		} catch (\SoapFault $e) {
			$result->error = 'SOAP Call Error: '.$e->getMessage();
		} finally {
			$result->request = $soapClient->__getLastRequest() ?? $soapClient->lastRequestXML;
			$result->response = $soapClient->__getLastResponse();
			$headers = $soapClient->__getLastResponseHeaders();
			if ($headers !== null) {
				preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers, $matches);
				if (isset($matches[1])) {
					$result->status = (int)$matches[1];
				}
			}
		}

		return $result;
	}

	/**
	 * Returns the specific location url
	 *
	 * @param string $operation
	 * @return ?string
	 */
	private function getLocationFromWSDL(string $operation): ?string
	{
		$locationList = $this->getWsdlOperations();
		if (empty($locationList[$operation])) {
			return null;
		}
		foreach ($locationList[$operation] as $portName => $location) {
			if (
				!empty($location) &&
				$this->test &&
				str_ends_with($portName, 'Pruebas')
			) {
				return $location;
			}
			if (
				!empty($location) &&
				!$this->test &&
				!str_ends_with($portName, 'Pruebas')
			) {
				return $location;
			}
		}
		return null;
	}

	/**
	 * Creates an array of operations and locations from the wsdl
	 *
	 * @return ?array
	 */
	private function getWsdlOperations(): ?array
	{
		$locationList = [];

		// Load the WSDL file as XML
		try {
			$xml = new \SimpleXMLElement(file_get_contents($this->wsdl));
		} catch (Exception $e) {
			return file_exists($this->wsdl) ? 'exists' : $e->getMessage();
		}

		// Namespaces used in WSDL files (SOAP-specific)
		$namespaces = $xml->getNamespaces(true);

		// Register namespaces (wsdl and soap namespaces)
		$xml->registerXPathNamespace("wsdl", $namespaces["wsdl"]);
		if (isset($namespaces["soap"])) {
			$xml->registerXPathNamespace("soap", $namespaces["soap"]);
		}

		// Extract <service> elements
		$services = $xml->xpath("//wsdl:service");

		foreach ($services as $service) {
			$serviceName = (string)$service["name"];
			// Extract the ports inside the service
			$ports = $service->xpath("./wsdl:port");
			foreach ($ports as $port) {
				$portName = (string)$port["name"];

				// Get the binding associated with this port
				$bindingName = (string)$port["binding"];
				$binding = $xml->xpath("//wsdl:binding[@name='"
					. explode(":", $bindingName)[1] . "']")[0];

				// Extract the address (endpoint URL)
				$address = $port->xpath("./soap:address");
				if ($address) {
					$location = (string)$address[0]["location"];
				} else continue;

				// Extract operations from the binding
				$operations = $binding->xpath("./wsdl:operation");
				foreach ($operations as $operation) {
					$operationName = (string)$operation["name"];
					$locationList[$operationName][$portName] = $location;
				}
			}
		}

		return $locationList;
	}

    /*
    private function getWsdlOperations(): ?array
{
    $locationList = [];

    try {
        $xml = new \SimpleXMLElement(file_get_contents($this->wsdl));
    } catch (Exception $e) {
        return file_exists($this->wsdl) ? 'exists' : $e->getMessage();
    }

    $namespaces = $xml->getNamespaces(true);

    // Register namespaces
    $xml->registerXPathNamespace("wsdl", $namespaces["wsdl"]);
    foreach ($namespaces as $prefix => $ns) {
        if (stripos($prefix, 'soap') !== false) {
            $xml->registerXPathNamespace($prefix, $ns);
        }
    }

    foreach ($xml->xpath("//wsdl:service") as $service) {
        foreach ($service->xpath("./wsdl:port") as $port) {

            // Find address (any SOAP namespace)
            $address = $port->xpath("*[local-name()='address']");
            if (!$address) continue;

            $location = (string)$address[0]["location"];

            // Determine binding reference
            $bindingName = (string)$port["binding"];
            $bindingLocal = strpos($bindingName, ':') !== false
                ? explode(":", $bindingName)[1]
                : $bindingName;

            $binding = $xml->xpath("//wsdl:binding[@name='{$bindingLocal}']")[0] ?? null;
            if (!$binding) continue;

            // Extract operations from binding
            foreach ($binding->xpath("./wsdl:operation") as $op) {
                $opName = (string)$op["name"];
                $portName = (string)$port["name"];

                $locationList[$opName][$portName] = $location;
            }
        }
    }

    return $locationList;
}
     */

}