<?php


use UzerpPhinx\UzerpMigration;

/**
 * Use concat_ws function (available since postgresSQL 9.1)
 * to join address details to create column fulladdress.
 * 
 * This avoids repeated commas in the output when an address
 * detail column contains an empty value.
 */
class PartyAddressOverviewUseConcatWs extends UzerpMigration
{
    public function up()
    {
        $view_name = 'partyaddressoverview';
        $view_owner = 'www-data';
        $view = <<<'VIEW'
CREATE OR REPLACE VIEW public.partyaddressoverview
AS
SELECT p.id,
concat_ws(', ', a.street1, a.street2, a.street3, a.town, a.county, a.postcode, c.name) AS fulladdress,
    a.street1,
    a.street2,
    a.street3,
    a.town,
    a.county,
    a.postcode,
    a.countrycode,
    c.name AS country,
    p.address_id,
    p.name,
    p.main,
    p.billing,
    p.shipping,
    p.payment,
    p.technical,
    p.party_id,
    p.parent_id,
    p.vatnumber,
    p.usercompanyid
    FROM partyaddress p
    JOIN address a ON p.address_id = a.id
    JOIN countries c ON c.code = a.countrycode;
VIEW;
        $this->query("select deps_save_and_drop_dependencies('public', '{$view_name}')");
        $this->query("DROP VIEW {$view_name}");
        $this->query($view);
        $this->query("ALTER TABLE {$view_name} OWNER TO \"{$view_owner}\"");
        $this->query("select deps_restore_dependencies('public', '{$view_name}')");
    }

    public function down()
    {
        $view_name = 'partyaddressoverview';
        $view_owner = 'www-data';
        $view = <<<'VIEW'
CREATE OR REPLACE VIEW public.partyaddressoverview
AS
SELECT p.id,
    ((((((COALESCE(a.street1, ''::character varying)::text || ','::text) || (COALESCE(a.street2, ''::character varying)::text || ','::text)) || (COALESCE(a.street3, ''::character varying)::text || ','::text)) || (COALESCE(a.town, ''::character varying)::text || ','::text)) || (COALESCE(a.county, ''::character varying)::text || ','::text)) || (COALESCE(a.postcode, ''::character varying)::text || ','::text)) || COALESCE(c.name, ''::character varying::bpchar::character varying)::text AS fulladdress,
    a.street1,
    a.street2,
    a.street3,
    a.town,
    a.county,
    a.postcode,
    a.countrycode,
    c.name AS country,
    p.address_id,
    p.name,
    p.main,
    p.billing,
    p.shipping,
    p.payment,
    p.technical,
    p.party_id,
    p.parent_id,
    p.vatnumber,
    p.usercompanyid
    FROM partyaddress p
    JOIN address a ON p.address_id = a.id
    JOIN countries c ON c.code = a.countrycode;
VIEW;
        $this->query("select deps_save_and_drop_dependencies('public', '{$view_name}')");
        $this->query("DROP VIEW {$view_name}");
        $this->query($view);
        $this->query("ALTER TABLE {$view_name} OWNER TO \"{$view_owner}\"");
        $this->query("select deps_restore_dependencies('public', '{$view_name}')");
    }
}
