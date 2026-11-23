import { useProfileApi } from "@CoreBundle/components/profile/ProfileApiContext";
import { Card, Descriptions, Flex, Tag, Tooltip, Typography } from "antd";
import React from "react";

// Assigned roles stand out, inherited ones keep the neutral tag color
const ASSIGNED_COLOR = 'blue';

/**
 * Groups the roles by bundle: site-wide roles (no bundle) first, then the bundles by name.
 *
 * @param {import("./ProfileApi").ProfileRole[]} roles
 * @returns {{ key: string, label: string, roles: import("./ProfileApi").ProfileRole[] }[]}
 */
function groupByBundle(roles) {
    const groups = new Map();
    for (const role of roles) {
        const key = role.bundle ?? '';
        if (!groups.has(key)) {
            groups.set(key, {
                key,
                label: role.bundle ? role.bundle.replace(/Bundle$/, '') : 'General',
                roles: [],
            });
        }
        groups.get(key).roles.push(role);
    }

    return [...groups.values()]
        .sort((a, b) => (b.key === '') - (a.key === '') || a.label.localeCompare(b.label))
        .map(group => ({ ...group, roles: group.roles.toSorted((a, b) => a.label.localeCompare(b.label)) }));
}

function RoleTag({ role }) {
    return <Tooltip title={`${role.name} (${role.assigned ? 'assigned' : 'inherited'})`}>
        <Tag color={role.assigned ? ASSIGNED_COLOR : undefined} style={{ marginInlineEnd: 0 }}>{role.label}</Tag>
    </Tooltip>;
}

/**
 * Roles of the logged-in user, assigned and inherited through the role hierarchy, grouped by bundle.
 */
export default function RolesCard() {
    const api = useProfileApi();
    const [roles, setRoles] = React.useState([]);
    const [loading, setLoading] = React.useState(true);

    React.useEffect(() => {
        api.withErrorHandling().getRoles()
            .then(res => setRoles(res.data))
            .catch(() => setRoles([]))
            .finally(() => setLoading(false));
    }, [api]);

    const groups = React.useMemo(() => groupByBundle(roles), [roles]);

    return <Card
        title="Roles"
        loading={loading}
        extra={<Flex gap="small">
            <Tag color={ASSIGNED_COLOR} style={{ marginInlineEnd: 0 }}>Assigned</Tag>
            <Tag style={{ marginInlineEnd: 0 }}>Inherited</Tag>
        </Flex>}
    >
        {groups.length > 0
            ? <Descriptions
                column={1}
                items={groups.map(group => ({
                    key: group.key || 'general',
                    label: group.label,
                    children: <Flex wrap gap="small">
                        {group.roles.map(role => <RoleTag key={role.name} role={role}/>)}
                    </Flex>,
                }))}
            />
            : <Typography.Text type="secondary">No roles</Typography.Text>}
    </Card>;
}
