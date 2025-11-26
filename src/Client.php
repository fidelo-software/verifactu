<?php

namespace FideloSoftware\Verifactu;

use Exception;

class Client
{
    private ?string $testWSDL = 'https://prewww2.aeat.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';

    private ?string $liveWSDL = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';

	private ?string $location = null;

	private ?Certificate $certificate = null;

	private bool $test = false;

	public function setTest(bool $test): Client
	{
		$this->test = $test;
		return $this;
	}

    private function getWSDLUrl(): string
    {
        return $this->test ? $this->testWSDL : $this->liveWSDL;
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

	public function callWithSoapClient(string $operation, string $data): Result
	{
		$result = new Result();
		if (!extension_loaded('soap')) {
			$result->error = 'SOAP extension not loaded.';
			return $result;
		}

		$location = trim($this->getLocation($operation));
        $data = unserialize($data);
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
			$soapClient = new SoapClient($this->getWSDLUrl(), $options);
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
                preg_match('/(Pruebas|Test|Pre)$/i', $portName)
			) {
				return $location;
			}
			if (
				!empty($location) &&
				!$this->test &&
				!preg_match('/(Pruebas|Test|Pre)$/i', $portName)
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

		try {
			$xml = new \SimpleXMLElement(file_get_contents($this->getWSDLUrl()));
		} catch (Exception $e) {
			return [];
		}

		$namespaces = $xml->getNamespaces(true);

		$xml->registerXPathNamespace("wsdl", $namespaces["wsdl"]);
        foreach (['soap', 'soap12', 'soapenv'] as $prefix) {
            if (isset($namespaces[$prefix])) {
                $xml->registerXPathNamespace($prefix, $namespaces[$prefix]);
            }
        }

		$services = $xml->xpath("//wsdl:service");

		foreach ($services as $service) {
			$serviceName = (string)$service["name"];
			$ports = $service->xpath("./wsdl:port");
			foreach ($ports as $port) {
				$portName = (string)$port["name"];

				$bindingName = (string)$port["binding"];
                $bindingLocalName = strpos($bindingName, ':') !== false
                    ? explode(":", $bindingName)[1]
                    : $bindingName;

                $bindingMatches = $xml->xpath("//wsdl:binding[@name='{$bindingLocalName}']");

                if (!$bindingMatches) {
                    continue;
                }

                $binding = $bindingMatches[0];

                $address = array_merge(
                    $port->xpath("./soap:address") ?: [],
                    $port->xpath("./soap12:address") ?: [],
                    $port->xpath("./soapenv:address") ?: []
                );
				if ($address) {
					$location = (string)$address[0]["location"];
				} else continue;

				$operations = $binding->xpath("./wsdl:operation");
				foreach ($operations as $operation) {
					$operationName = (string)$operation["name"];
					$locationList[$operationName][$portName] = $location;
				}
			}
		}

		return $locationList;
	}

}