import AccountStatusTags, { getReloginState } from "@GryphlineBundle/components/account/AccountStatusTags";
import AddAccountModal from "@GryphlineBundle/components/account/AddAccountModal";
import ProfileSettingsSections from "@GryphlineBundle/components/game-profile/ProfileSettingsSections";
import { AppstoreOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Gryphline descriptor for the games SPA (see @GamesBundle/providers).
 */
export default {
    key: "gryphline",
    label: "Gryphline",
    icon: <AppstoreOutlined/>,
    AddAccountModal,
    AccountStatusTags,
    getReloginState,
    profileSettings: {
        // Fallback values for settings present on the profile but still null
        defaults: {
            skport_check_in: true,
            skport_missed_check_in: true,
            stamina_check: false,
            stamina_threshold: -1,
            dailies_check: false,
            weeklies_check: false,
            sync_pulls: false,
        },
        Sections: ProfileSettingsSections,
    },
};
