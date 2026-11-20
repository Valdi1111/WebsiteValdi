import AccountsPage from "@HoyoverseBundle/components/account/AccountsPage";
import DiaryPage from "@HoyoverseBundle/components/diary/DiaryPage";
import PullsPage from "@HoyoverseBundle/components/pulls/PullsPage";
import { UserSwitchOutlined } from "@ant-design/icons";
import React from "react";

/**
 * HoYoverse descriptor for the games SPA (see @GamesBundle/providers).
 */
export default {
    key: "hoyoverse",
    label: "HoYoverse",
    icon: <UserSwitchOutlined/>,
    AccountsPage,
    PullsPage,
    extraRoutes: [
        { path: "accounts/:accountId/profiles/:gameProfileId/diary", element: <DiaryPage/> },
    ],
};
