
Uvaimex, or Omeka-s Import and Export
=====================================

This script is used in relation to Omeka-s installations, to learn more about Omeka-s check their website www.omeka.org.
Omeka-s allows mutiple sites instances within a singe installation, but does not provide an easy way to import or export singe sites from an installation to another. 
Uvaimex is able to export and then import a single Omeka-s site into separate installations.
It perform the export function by interacting directly with the Omeka-s detabases and by generating a single json file that contains all records needed for the selected site.
In order to perform the export function you need to know the siteslug of the site you want to export.
 
To list the siteslugs available:
$ php ./uvaimex.php  --action="siteslug" --config="omaka-s-dabacase.ini-location"

To export a site use this sintax:
$ php ./uvaimex.php --action="export" --siteslug="the-site-slug-you-want-to-export" --file="export-file-name" --config="omaka-s-dabacase.ini-location"

Once a site has been exported you can import it into another Omake-s installation.
To import a site use this sintax:
$ php ./uvaimex.php  --action="import" --file="import-file-name" --config="omaka-s-dabacase.ini-location"

Because new versions of Omeka-s and modules installed might modify the database, it is recommended that both sites are upgraded to the same version of both the core Omeka-s version and the installed modules.
In addition both sites should have an identical set of mudules installed.


Additional Options and Considerations
-------------------------------------

Sometimes it might be useful to check the modules versions and active theme from the command line, this command will list the installed modules and versions in a given installation:
$ php ./uvaimex.php  --action="info" --config="omaka-s-dabacase.ini-location"

This command will list the installed modules and versions stored in a given exported file:
$ php ./uvaimex.php  --action="info" --file="import-file-name"

Always make sure you have a backup of the database and files of you Omeka-s installation before running this script.

When importing a site you might find an issue with writing the assets and item files in the tipical Omeka-s directory normally located in a directory (named: /files) located under the root foleder of the site (often /public_html).
This is because this directory needs to be writable by apache or nginx or whatevrr webserver is being used and might not be writable by the user running the script. In this situation you could temporarily change the permissions of this directory and allow the user running the script to write files in that directory, another option is to write the files elesewhere and move them after the script has ran, consult with your IT to detrmine what is teh best option.
If you choose to write the files in a different location and move then later you need to add an additional configuration option when running the import script.

$ php ./uvaimex.php  --action="import" --file="import-file-name" --config="omaka-s-dabacase.ini-location" --writefiles="directory-where-the-files-should-be-written"

Another possible issue you might encounter is an out of memory error, which would look something like this: "PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 42426368 bytes)".
This kind of error can occur when a site you are exporting is too large compared to the amount of memory allocated to php in your system.
In this case you will need to edit the uvaimex.php itself and increase the value of line 9 "ini_set(\'memory_limit\',\'1G\');"
You can change 1G to 2G or more as long as your system has sufficient physical memory.



DEVELOPMENT INFORMATION
=======================

This script has been developped simply following Omeka-s database and files structure and modifying these directly rather than creating a module and use the built in functions that Omeka-s provides. If you are interested in modifying the following notes should be useful.

Let's start explain gin how the export process works.
Under the hood, while exporting, Uvaimex goes trough most of the Omeka-s database tables selecting the records that belong to a specific site. It also creates a new table, called "uvaimexguid" that will store information useful to identify records previously imported or exported. Let's look at this table structure for a moment. The uvaimexguid table has 5 columns, `id`, `guid`, `relatedID`, `related_varchar` and `related_table`.
The first column is technically redundant, it is an internal unique identifier with a auto increment, the script actually never uses this column, but it is my personal preference to always have a unique id in a table. The `guid` column contains a string, for every record that we export we will create a new `guid` that will accompany the record even when transferred into a different installation. the column `relatedID` is used to store the corresponding id in the Omeka-s table, while the `related_table` stores the name of the Omeka-s table form which the record comes from.
There are situations in which the Omeka-s tables don't use a numerical id, in these cases we use `related_varchar` to store the related ID. There are additional cases in which the Omeka-s tables use a combination of a numeric id paired with some other information to uniquely identify records, in these cases we store the id in the relatedID field and the other information in the `related_varchar` field. For example the Omeka-s table `site_settings` has 3 fields id, site_id, value. The field id is a string, the site_id is a int related to the site table and the value is the value of that specific prefrence for that specific site. In this case we store site_id under relatedID and id under related_varchar, this way we can always connect a record for a specific site preference to a specific guid that we generated the first time we exported the record. This same `guid` information will be stored in both the site we exported the records from and that in which we imported the records into. Even if the actual numeric site_id might be different in the two installations we are able to connect the two records and update it, rather than create a new one.

Before i was saying that "Uvaimex goes trough most of the Omeka-s database tables selecting the records that belong to a specific site", while doing this we create records in the uvaimexguid table and we store all the values in a array. Once we collected all the records we were looking for we go through these records again and remove all the id's (which are specific of this installation) and converts them into guid. Eventually the script will convert this big tray into a json file and save it, before doing that we need to do one more thing, which is collect all the images that have been stored either as items or as assets. We elected them all, convert them into base64 values and store these values in the general array, that is then written as json.

At this point we have a json file that contains all the records and all the files related to the site we exported.

Let's quickly look at the import process.
When importing the records we perform the same process as exporting, only in reverse. We first check if a record associated with our guid has already been created (it might have been in previous import) if it doesn't exist we create it, but the `id` we will store in this case is not that of the original site but the one from this site. As we import all the records we are also populating the uvaimexguid table, the `guid` will always stay the same independently of the installation, while the id will be specific to each installation an allow us to match the records.
Once we are done importing the records we save all the files converting them back from base64 to binary.


TABLE SPECIFIC NOTES
====================

While developing Uvaimex it was sometimes necessary to take special actions in order to properly export or import the records here i list, table by table a few notes. Not all tables are listed here, only those i felt there was a need to keep some info about it.

user
----

ids: id

in export
nothing special

in import
check if a user with the same email, even if not imported prior, already exists, if so use this id


user_setting
------------

ids: id (this is a string), user_id
uvaimexguid values keys are both id and user_id

in export
nothing special

in import
if user was already present in installation (user_email_already_existed set to true), don't update the settings


site
----

ids: id (site_id), thumbnail_id (asset_id), homepage_id (page_id), owner_id (user_id)

in export
thumbnail_id and owner_id must be added to the lost of "to be included records"
homepage_id is not relevant as this page_id will be included as part of the site anyway.
the site navigation most ion the times will store the page id's we need to convert them into guid

in import
records must be imported after user
convert user_guid to local user_id
initially set thumbnail_id, homepage_id as NULL since we will create these records later.
After initial record recreation come back to site and update the record with thumbnail_id, homepage_id
The site navigation guid must be converted into the new id's

site_item_set
-------------

ids: id (site_item_set_id), site_id, item_set_id

in export
record search is limited to site_id
item_set_id must be added to the lost of "to be included records"

in import
records must be imported after site and item_set
convert site_guid to local site_id

site_page
---------

ids: id (site_page_id), site_id

in export
record search is limited to site_id

in import
records must be imported after site
convert site_guid to local site_id


site_page_block
---------------

ids: id (site_page_block_id), page_id (site_page_id)

in export
record search is limited to site_page_id's 

in import
records must be imported after site_page
convert site_page_guid to local site_page_id


site_setting
------------

ids: id (site_setting_id), site_id
id is not numeric and it is only unique when combined with site_id

in export
record search is limited to site_id

in import
records must be imported after site
convert site_guid to local site_id


site_block_attachment
---------------------

ids: id (site_block_attachment_id), block_id (site_page_block_id), item_id, media_id

in export
record search is limited to block_id
add item_id and media_id must be added to the list of "to be included records"

in import
item and media must be imported first


item_set
--------

ids: id (item_set_id)

in export
limit export to collected item_set_id's

in import
nothing to signal


item
----

ids: id (item_id), primary_media_id (media_id)

in export
add media_id must be added to the lost of "to be included records"

in import
media_id must be initially set to NULL until media had been processed


item_item_set
-------------

connects items and item sets, only two columns item_id and item_set_id

item_site
---------

connects items and sites, only two columns item_id and site_id


asset
-----

ids: id, owner_id (user_id)


item
----

media
-----

resource
--------

The resource_type of this table tells us the relation, with resource_type = Omeka\Entity\ItemSet the id will be that of the item_set, if it is Omeka\Entity\Item or Omeka\Entity\Media it will relate to item or media


fulltext_search
---------------

ids: id, owner_id (user_id)
the id used in fulltext_search is the corresponding id based on the column `resource` so that, for example, id 4 with resource = 'site_pages' is id 4 of site_page


