<?php

// Only want this compatibility shim for < PHP 8.0 
if (PHP_VERSION_ID < 80000 && !class_exists('Pakettikauppa\\SimpleXMLElement', false)) {
    class Pakettikauppa_SimpleXMLElement_Fixed extends \SimpleXMLElement
    {
        #[\ReturnTypeWillChange]
        public function addChild($name, $value = null, $ns = null)
        {
            if ($value != null) {
                $value = htmlspecialchars($value, ENT_XML1);
            }
            return parent::addChild($name, $value, $ns);
        }
    }
    class_alias(Pakettikauppa_SimpleXMLElement_Fixed::class, 'Pakettikauppa\\SimpleXMLElement');
}

