import React from "react";

export default function TocItem({ close, item, level, chapter, navigateTo }) {
    function active() {
        if (chapter && item.id === chapter.id) {
            return "active";
        }
        return "";
    }

    /**
     * Navigate to chapter, ignore if href is null (section with chapters)
     */
    function onClick() {
        if (item.href) {
            navigateTo(item.href);
            close();
        }
    }

    return (
        <>
            <li className="cursor-pointer">
                    <span className={`dropdown-item text-truncate ${active()}`} title={item.label}
                          onClick={onClick} style={{ padding: `0.25rem ${level}.5rem` }}>
                        {item.label}
                    </span>
            </li>
            {item.subitems.map(i =>
                <TocItem key={i.id} close={close} item={i} level={level + 1} chapter={chapter} navigateTo={navigateTo}/>
            )}
        </>
    );
}
