import AccountsPage from "@GryphlineBundle/components/account/AccountsPage";
import PullsPage from "@GryphlineBundle/components/pulls/PullsPage";
import { AppstoreOutlined } from "@ant-design/icons";
import React from "react";

/**
 * Gryphline descriptor for the games SPA (see @GamesBundle/providers).
 */
export default {
    key: "gryphline",
    label: "Gryphline",
    icon: <AppstoreOutlined/>,
    AccountsPage,
    PullsPage,
};
