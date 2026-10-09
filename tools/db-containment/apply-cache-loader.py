#!/usr/bin/env python3
"""Patch a downloaded/current plugin loader without replacing unrelated endpoints or its version."""
import pathlib,sys
path=pathlib.Path(sys.argv[1]);text=path.read_text()
block="""require_once EXOTIC_CRM_SYNC_PATH . 'includes/class-containment-cache-endpoint.php';
add_action('rest_api_init', function () { (new Exotic_Containment_Cache_Endpoint())->register_routes(); });
register_activation_hook(__FILE__, ['Exotic_Containment_Cache_Endpoint', 'install']);
add_action('init', ['Exotic_Containment_Cache_Endpoint', 'maybe_upgrade']);
"""
marker='// Load endpoint classes'
if 'class-containment-cache-endpoint.php' in text:
    if "['Exotic_Containment_Cache_Endpoint', 'maybe_upgrade']" not in text:text=text.replace("register_activation_hook(__FILE__, ['Exotic_Containment_Cache_Endpoint', 'install']);","register_activation_hook(__FILE__, ['Exotic_Containment_Cache_Endpoint', 'install']);\nadd_action('init', ['Exotic_Containment_Cache_Endpoint', 'maybe_upgrade']);")
elif marker in text:text=text.replace(marker,marker+'\n'+block,1)
else:raise SystemExit('Expected loader marker missing; inspect this plugin version before editing.')
path.write_text(text);print('Containment loader patched; existing plugin version and other endpoints preserved.')
