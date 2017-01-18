Installation and configuration
==============================

This installation needs to be executed by someone with Puppet experience and
admin rights on your PuppetDB server. This module needs a Certificate granted
access to your PuppetDB. You could create and sign a dedicated cert, this guide
shows you how to use the Puppet certificate that already exists on your Director
host.

On your PuppetDB server, you must put this certificate to your whitelist. This
is usually `/etc/puppetlabs/puppetdb/certificate_whitelist`. You probably need
to restart your PuppetDB after changing this file.


Installation
------------

As with any Icinga Web 2 module, installation is pretty straight-forward. In
case you're installing it from source all you have to do is to drop the `puppetdb`
module in one of your module paths. You can examine (and set) the module path(s)
in `Configuration / Application / General`. In a typical environment you'll probably
drop the module to `/usr/share/icingaweb2/modules/puppetdb`. Please note that the
directory name MUST be `puppetdb` and not `icingaweb2-module-puppetdb` or anything
else.


Configuration
-------------

There is currently no web-based configuration. You must manually create a `ssl`
directory in your `puppetdb` config path (`/etc/icingaweb2/modules/puppetdb`).
In this directory please create subdirectories for each of the PuppetDB hosts
you want to work with. The expected directory structure equals a typical Puppet
SSL dir. This makes manual setup trickier while being helpful in a Puppet-based
environment.

The following script shows how manual setup using existing certificates could
work:

```sh
PUPPETDB_HOST=puppetdb.example.com
MY_CERTNAME=$(hostname --fqdn)
WEB_SSLDIR=/etc/icingaweb2/modules/puppetdb/ssl
mkdir -p $WEB_SSLDIR
cp -r $(puppet agent --configprint ssldir) $WEB_SSLDIR/$PUPPETDB_HOST
cd $WEB_SSLDIR/$PUPPETDB_HOST
cat private_keys/${MY_CERTNAME}.pem certs/${MY_CERTNAME}.pem \
  > private_keys/${MY_CERTNAME}_combined.pem
```


Puppet-based configuration
--------------------------

As you're interested in this module you're probably running a Puppet-driven
environment. This is what your manifest section preparing a similar config
could look like:

```puppet
$puppetdb_host = 'puppetdb.example.com'
$my_certname   = $::fqdn
$ssldir        = $::settings::ssldir
$web_ssldir    = '/etc/icingaweb2/modules/puppetdb/ssl'
$ssl_subdir    = "${web_ssldir}/${puppetdb_host}"

file { $web_ssldir:
  ensure => directory,
}

file { $ssl_subdir:
  ensure  => directory,
  source  => $ssldir,
  recurse => true,
}

~> exec { "Generate combined .pem file for $puppetdb_host":
  command     => "cat private_keys/${my_certname}.pem certs/${my_certname}.pem > private_keys/${my_certname}_combined.pem",
  path        => ["/usr/bin", "/usr/sbin"],
  cwd         => $ssl_subdir,
  refreshonly => true
}
```
