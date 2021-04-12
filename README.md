Reciprocal (module for Omeka S)
===============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Reciprocal] is a module for [Omeka S] that allows to update automatically the
linked resources with the reciprocal value in the reciprocal property.

For example, when the property "Dublin Core : Is Part Of" of the item Alpha is
filled with the value "item Beta", a value "item Alpha" is added to the item Beta
in the property "Dublin Core : Has Part". When the value is removed, the
reciprocal value is removed too.

The list of the most common reciprocal properties is ready for the default
ontologies and can be updated for any new ontology.


Installation
------------

This optional module [Generic] may be installed first.

See general end user documentation for [installing a module].

* From the zip

Download the last release [Reciprocal.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Reciprocal`.


Usage
-----

Simply fill the list of the reciprocal properties in the config of the module.

To update all existing resources, simply run a batch edit on all resources.

**Warning**: The linked resource is updated only if the user has the right to
update it. No change is done on the linked resource else.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Copyright
---------

* Copyright Daniel Berthereau, 2020 (see [Daniel-KM] on GitLab)

These features are built for the future digital library [Manioc] of the
Université des Antilles and Université de la Guyane, currently managed with
[Greenstone].


[Reciprocal]: https://gitlab.com/Daniel-KM/Omeka-S-module-Reciprocal
[Omeka S]: https://omeka.org/s
[Installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Reciprocal.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Reciprocal/-/releases
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Reciprocal/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: http://http://opensource.org/licenses/MIT
[Manioc]: http://www.manioc.org
[Greenstone]: http://www.greenstone.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
