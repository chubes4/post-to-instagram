import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, TabPanel, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const ScheduledPostItem = ({ post }) => {
    return (
        <li className="pti-scheduled-item">
            <div className="pti-item-info">
                {sprintf(
                    _n('%d image', '%d images', post.image_ids.length, 'post-to-instagram'),
                    post.image_ids.length
                )}
                {' '}{__('for', 'post-to-instagram')}{' '}
                <strong>{new Date(post.schedule_time).toLocaleString()}</strong>
            </div>
            <div className="pti-item-actions">
                <Button isSmall variant="secondary" disabled>{__('Edit', 'post-to-instagram')}</Button>
                <Button isSmall isDestructive disabled>{__('Cancel', 'post-to-instagram')}</Button>
            </div>
        </li>
    );
};


const ScheduledPosts = ({ postId, refreshKey }) => {
    const [thisPostScheduled, setThisPostScheduled] = useState([]);
    const [allScheduled, setAllScheduled] = useState([]);
    const [isFetchingThis, setIsFetchingThis] = useState(true);
    const [isFetchingAll, setIsFetchingAll] = useState(false);
    const [activeTab, setActiveTab] = useState('thisPost');

    const fetchThisPostScheduled = () => {
        setIsFetchingThis(true);
        apiFetch({ path: `/pti/v1/scheduled-posts?post_id=${postId}` })
            .then(posts => {
                setThisPostScheduled(posts);
                setIsFetchingThis(false);
            })
            .catch(error => {
                console.error('Error fetching scheduled posts for this post:', error);
                setIsFetchingThis(false);
            });
    };

    const fetchAllScheduled = () => {
        setIsFetchingAll(true);
        apiFetch({ path: `/pti/v1/scheduled-posts` })
            .then(posts => {
                setAllScheduled(posts);
                setIsFetchingAll(false);
            })
            .catch(error => {
                console.error('Error fetching all scheduled posts:', error);
                setIsFetchingAll(false);
            });
    };
    
    // Fetch for "This Post" on initial mount and when refreshKey changes
    useEffect(() => {
        fetchThisPostScheduled();
    }, [postId, refreshKey]);

    const onSelectTab = (tabName) => {
        setActiveTab(tabName);
        if (tabName === 'allPosts' && allScheduled.length === 0) {
            fetchAllScheduled();
        }
    }

    return (
        <div className="pti-scheduled-posts-section">
            <hr/>
            <h3>{__('Scheduled Posts', 'post-to-instagram')}</h3>
            <TabPanel
                className="pti-scheduled-posts-tabs"
                activeClass="is-active"
                onSelect={onSelectTab}
                tabs={[
                    {
                        name: 'thisPost',
                        title: __('This Post', 'post-to-instagram'),
                        className: 'tab-this-post',
                    },
                    {
                        name: 'allPosts',
                        title: __('All Posts', 'post-to-instagram'),
                        className: 'tab-all-posts',
                    },
                ]}
            >
                {(tab) => (
                    <ul className="pti-scheduled-list">
                        {tab.name === 'thisPost' && (
                            isFetchingThis ? <Spinner/> :
                            thisPostScheduled.length > 0 ? 
                            thisPostScheduled.map(p => <ScheduledPostItem key={p.id} post={p} />) :
                            <p>{__('No posts scheduled for this entry.', 'post-to-instagram')}</p>
                        )}
                        {tab.name === 'allPosts' && (
                            isFetchingAll ? <Spinner/> :
                            allScheduled.length > 0 ?
                            allScheduled.map(p => <ScheduledPostItem key={p.id} post={p} />) :
                            <p>{__('No scheduled posts found site-wide.', 'post-to-instagram')}</p>
                        )}
                    </ul>
                )}
            </TabPanel>
        </div>
    );
};

export default ScheduledPosts; 