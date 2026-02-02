Component,Referral Table,Action Plan Table,Goal Table
Display Name,ReferralToAP,Action Plan,Goal
Logical Name,crd88_referraltoap,crd88_actionplan,crd88_goal
Schema Name,crd88_ReferralToAP,crd88_ActionPlan,crd88_Goal
Entity Set Name,crd88_referraltoaps,crd88_actionplans,crd88_goals
Primary Key (ID),crd88_referraltoapid,crd88_actionplanid,crd88_goalid
Primary Name Field,crd88_refid,crd88_actionid,crd88_goalid1
Object Type Code,10772,10802,10812


Property,Details
Principal Table (One),crd88_referraltoap
Related Table (Many),crd88_actionplan
Lookup Column Name,crd88_referralid (Typically created on Action Plan)
Behavior,"When a Referral is selected, the app should display all associated Action Plans. Deleting a Referral should ideally restrict or cascade delete associated Plans."


Property,Details
Principal Table (One),crd88_actionplan
Related Table (Many),crd88_goal
Lookup Column Name,crd88_actionplanid_lookup (Typically created on Goal)
Behavior,"Each Goal is a line item belonging to a specific Action Plan. The UI should allow ""Adding a Goal"" directly from the Action Plan view, auto-populating this relationship."

3. Visualizing the Data Hierarchy
This is a Grandparent → Parent → Child structure.
Referral (crd88_referraltoap)└── Action Plan (crd88_actionplan)└── Goal (crd88_goal)