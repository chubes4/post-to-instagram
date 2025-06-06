import { __ } from '@wordpress/i18n';

const CaptionInput = ({ value, onChange, disabled }) => (
    <div className="pti-caption-box">
        <label className="pti-caption-label">
            {__('Instagram Caption', 'post-to-instagram')}
        </label>
        <textarea
            className="pti-caption-input"
            value={value}
            onChange={e => onChange(e.target.value)}
            rows={4}
            placeholder={__('Write your Instagram caption here...', 'post-to-instagram')}
            disabled={disabled}
        />
    </div>
);

export default CaptionInput; 