import { UserOutlined } from "@ant-design/icons";
import { Avatar } from "antd";
import React from "react";

// Background of the initial, picked by user id so that each user keeps the same one
const INITIAL_COLORS = ['#f56a00', '#7265e6', '#ffbf00', '#00a2ae', '#87d068', '#1677ff', '#eb2f96', '#722ed1'];

/**
 * Profile image of a user: the uploaded one, or the initial of the email on a color of its own
 * (also when the uploaded one fails to load). Other props (size, ref and the handlers of a Dropdown...)
 * go to the antd Avatar.
 *
 * @param {{ user: import("./CurrentUserContext").CurrentUser|null }} props
 */
export default function UserAvatar({ user, style, ...props }) {
    if (!user) {
        return <Avatar icon={<UserOutlined/>} style={style} {...props}/>;
    }

    return <Avatar
        src={user.avatar_url || undefined}
        alt={user.email}
        style={{ backgroundColor: INITIAL_COLORS[user.id % INITIAL_COLORS.length], ...style }}
        {...props}
    >
        {(user.email || '?').charAt(0).toUpperCase()}
    </Avatar>;
}
