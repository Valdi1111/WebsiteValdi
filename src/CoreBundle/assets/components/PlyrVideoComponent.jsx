import React from "react";
import "plyr/dist/plyr.css";
import Plyr from "plyr";

/**
 * Plyr wrapper for video
 * @param {string} src base api url
 * @param {string} type file type
 * @returns {JSX.Element}
 * @constructor
 */
export default function PlyrVideoComponent({ src, type = "video/mp4" }) {
    /**
     * Reference to div container
     * @type {React.MutableRefObject<HTMLDivElement>}
     */
    const containerRef = React.useRef(null);
    /**
     * Reference to Plyr instance
     * @type {React.MutableRefObject<Plyr>}
     */
    const plyrInstance = React.useRef();

    React.useEffect(() => {
        if (!containerRef.current) {
            return;
        }

        // Create video element handled by Plyr (not by React)
        const video = document.createElement("video");
        video.src = src;
        video.setAttribute("playsinline", "");
        video.setAttribute("controls", "");
        containerRef.current.appendChild(video);

        // Observer listening to container size
        const resizeObserver = new ResizeObserver(entries => {
            for (let entry of entries) {
                const volumeControl = containerRef.current.querySelector(".plyr__controls__item.plyr__volume");
                if (volumeControl) {
                    volumeControl.style.display = entry.contentRect.width > 768 ? "flex" : "none";
                }
                const pipControl = containerRef.current.querySelector(".plyr__controls__item.plyr__control[data-plyr='pip']");
                if (pipControl) {
                    pipControl.style.display = entry.contentRect.width > 768 ? "inline-block" : "none";
                }
            }
        });
        resizeObserver.observe(video);

        // Initialize Plyr
        plyrInstance.current = new Plyr(video, {
            keyboard: {
                focus: true,
                global: true,
            },
            tooltips: {
                controls: true,
                seek: true
            },
            controls: [
                'play-large', // The large play button in the center
                //'restart', // Restart playback
                'rewind', // Rewind by the seek time (default 10 seconds)
                'play', // Play/pause playback
                'fast-forward', // Fast forward by the seek time (default 10 seconds)
                'progress', // The progress bar and scrubber for playback and buffering
                'current-time', // The current time of playback
                //'duration', // The full duration of the media
                'mute', // Toggle mute
                'volume', // Volume control
                'captions', // Toggle captions
                'settings', // Settings menu
                'pip', // Picture-in-picture (currently Safari only)
                'airplay', // Airplay (currently Safari only)
                //'download', // Show a download button with a link to either the current source or a custom URL you specify in your options
                'fullscreen', // Toggle fullscreen
            ]
        });
        // Cleanup
        return () => {
            try {
                plyrInstance.current?.destroy();
                plyrInstance.current = null;
            } catch (e) {
                console.warn("Error on Plyr cleanup:", e);
            }

            // Remove any additional nodes
            if (containerRef.current) {
                containerRef.current.innerHTML = "";
            }
        };
    }, [src]);

    return <div ref={containerRef}/>;

}