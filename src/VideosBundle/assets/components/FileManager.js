import MainLayout from "@VideosBundle/components/MainLayout";
import React from "react";

export default function FileManager() {
    const divRef = React.useRef();
    React.useEffect(() => {
        webix.ready(function () {
            // use custom scrolls, optional
            webix.CustomScroll.init();

            const app = new fileManager.App({
                url: "/api/fileManager/",
                override: new Map([
                    [fileManager.services.Backend, Backend],
                    [fileManager.services.Operations, Operations],
                ]),
            });
            app.render(divRef.current);
        });
    }, []);

    return (
        <MainLayout>
            <div ref={divRef} className="flex-grow-1"></div>
        </MainLayout>
    );

}

class Backend extends fileManager.services.Backend {

    openLink(id, download) {
        return `videos?path=${encodeURIComponent(id)}`
    }

}

class Operations extends fileManager.services.Operations {

    /**
     * Opens files in new browser tabs
     * @param {Array} files - an array of file data objects
     */
    open(files) {
        if (!files) files = this.state.selectedItem;
        for (let i = 0; i < files.length; ++i) {
            if (files[i].type === "video") {
                window.open(this.backend().openLink(files[i].id), "_blank");
            } else if (files[i].type !== "folder") {
                window.open(this.backend().directLink(files[i].id), "_blank");
            }
        }
    }

}