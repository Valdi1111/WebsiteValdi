import AccountStatusTags, { getReloginState } from "@PerfectworldBundle/components/account/AccountStatusTags";
import AddAccountModal from "@PerfectworldBundle/components/account/AddAccountModal";
import { CompassOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Perfect World descriptor for the games SPA (see @GamesBundle/providers).
 * Profiles have no automation settings and pulls are imported manually.
 */
export default {
    key: "perfectworld",
    label: "Perfect World",
    icon: <CompassOutlined/>,
    AddAccountModal,
    AccountStatusTags,
    getReloginState,
    profileSettings: {
        defaults: {},
        Sections: null,
    },
    pullsExtraImporters: [],
};
