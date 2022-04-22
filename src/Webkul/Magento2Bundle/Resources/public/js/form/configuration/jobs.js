'use strict';

define([
        'underscore',
        'jquery',
        'routing',
        'pim/form/common/save',
        'pim/template/form/save'
    ],
    function(
        _,
        $,
        Routing,
        SaveForm,
        template
    ) {
        return SaveForm.extend({
            template: _.template(template),
            currentKey: 'current_form_tab',
            events: {
                'click *': 'gotoJobs'
            },
            intialize: function() {
                this.render();
            },
            /**
             * {@inheritdoc}
             */
            render: function () {
                this.$el.html(this.template({
                    label: _.__('magento2.view.jobs')
                }));
                this.$el.find('.AknButton.AknButton--apply.save').css('margin', '0px 2px');
            },

            /**
             * {@inheritdoc}
             */
            gotoJobs: function() {
                sessionStorage.setItem('export-profile-grid.filters','i=1&p=25&s%5Blabel%5D=-1&f%5Bconnector%5D%5Bvalue%5D%5B%5D=Webkul+Magento2+Akeneo+Connector&t=export-profile-grid');
                var route = '#'+Routing.generate('pim_importexport_export_profile_index', {});
                window.location = route;
            },
        });
    }
);