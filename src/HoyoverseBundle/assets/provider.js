import AccountStatusTags, { getReloginState } from "@HoyoverseBundle/components/account/AccountStatusTags";
import AddAccountModal from "@HoyoverseBundle/components/account/AddAccountModal";
import DiaryPage from "@HoyoverseBundle/components/diary/DiaryPage";
import PowershellPullImportModal from "@HoyoverseBundle/components/game-profile/PowershellPullImportModal";
import SRankHistoryImportModal from "@HoyoverseBundle/components/game-profile/SRankHistoryImportModal";
import ProfileSettingsSections from "@HoyoverseBundle/components/game-profile/ProfileSettingsSections";
import { HistoryOutlined, UserSwitchOutlined, WindowsOutlined } from "@ant-design/icons";
import React from "react";

/**
 * HoYoverse descriptor for the games SPA (see @GamesBundle/providers).
 */
export default {
    key: "hoyoverse",
    label: "HoYoverse",
    icon: <UserSwitchOutlined/>,
    AddAccountModal,
    AccountStatusTags,
    getReloginState,
    profileSettings: {
        // Fallback values for settings present on the profile but still null
        defaults: {
            hoyolab_check_in: true,
            hoyolab_missed_check_in: true,
            code_redeem: true,
            sync_diary: false,
            sync_pulls: false,
            stamina_check: false,
            stamina_threshold: -1,
            expeditions_check: true,
            realm_currency_check: false,
            realm_currency_threshold: -1,
            shop_status_check: false,
            mimo_check: false,
            mimo_redeem: false,
            mimo_redeem_draw: false,
            mimo_lottery: false,
            mimo_reserve_points: -1,
            hilichurl_check: false,
            hilichurl_redeem: false,
            dailies_check: false,
            weeklies_check: false,
            endgames_check: false,
            // Resolved by the backend for every mode (EndgameSettings); empty only for games without endgames
            endgames_settings: {},
        },
        Sections: ProfileSettingsSections,
    },
    pullsExtraImporters: [
        {
            key: "powershell",
            label: "Import via PowerShell",
            icon: <WindowsOutlined/>,
            isAvailable: (profile) => Boolean(profile?.supports_powershell_pulls),
            Modal: PowershellPullImportModal,
        },
        {
            key: "s_rank_history",
            label: "Import S-rank history from HoYoLAB",
            icon: <HistoryOutlined/>,
            isAvailable: (profile) => Boolean(profile?.supports_s_rank_history_import),
            Modal: SRankHistoryImportModal,
        },
    ],
    // Tag of the S rows created from the official S-rank history (origin "official")
    officialPullLabel: "HoYoLAB",
    extraRoutes: [
        { path: "accounts/:accountId/profiles/:gameProfileId/diary", element: <DiaryPage/> },
    ],
};
