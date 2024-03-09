import Plyr from "plyr";
import "plyr/dist/plyr.css";
import {useSearchParams} from "react-router-dom";
import React from "react";

/**
 * Plyr wrapper
 * @param {function} apiUrl base api url
 * @returns {JSX.Element}
 * @constructor
 */
export default function VideoPlayer({apiUrl}) {
    const [searchParams, setSearchParams] = useSearchParams();

    React.useEffect(() => {
        const player = new Plyr('#player', {
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
                'duration', // The full duration of the media
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
        player.on('ready', (e) => {
            const instance = e.detail.plyr;
            instance.fullscreen.enter();
        });
    }, []);

    return (
        <main className="d-flex flex-column vh-100 vw-100">
            <video id="player">
                <source src={apiUrl(searchParams.get('id'))} type="video/mp4"/>
            </video>
        </main>
    );

}