import { Avatar } from "antd";
import React, { useEffect, useState } from "react";

/**
 * Square avatar of a game profile: its icon, or the first letter of the game name when there is none or it fails
 * to load. Greyed out when the profile is inactive; active defaults to profile.active (the settings drawer passes
 * the value being edited).
 */
export default function GameProfileAvatar({ profile, size = 48, active }) {
    const [imgError, setImgError] = useState(false);
    const isActive = active ?? profile.active;

    useEffect(() => {
        setImgError(false);
    }, [profile.icon_url]);

    return (
        <Avatar
            src={
                profile.icon_url && !imgError ? (
                    <img
                        src={profile.icon_url}
                        alt={profile.game_name}
                        referrerPolicy="no-referrer"
                        onError={() => setImgError(true)}
                    />
                ) : undefined
            }
            shape="square"
            size={size}
            style={{ filter: isActive ? "none" : "grayscale(100%)" }}
        >
            {profile.game_name?.[0] ?? "G"}
        </Avatar>
    );
}
