<?php

namespace Omnibus\GLS;

use Omnibus\Core\GatewayFactory;
use Omnibus\GLS\Action\ShippingAction;
use Omnibus\GLS\Action\TrackingAction;
use Omnibus\GLS\Action\PackingSlipAction;

class GLSGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config)
    {
        $config->defaults([
            'omnibus.factory_name' => 'gls',
            'omnibus.factory_title' => 'GLS'
        ]);
    }
}