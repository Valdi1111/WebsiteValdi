import DiscordAccountRow from "@CoreBundle/components/profile/DiscordAccountRow";
import TelegramAccountRow from "@CoreBundle/components/profile/TelegramAccountRow";
import { Card, Divider } from "antd";
import React from "react";

/**
 * External accounts linked to the site user, one LinkedAccountRow per provider.
 */
export default function ConnectedAccountsCard() {
    return <Card title="Connected accounts">
        <DiscordAccountRow/>
        <Divider/>
        <TelegramAccountRow/>
    </Card>;
}
