{** 
 *	(c) 2017 uzERP LLP (support#uzerp.com). All rights reserved. 
 * 
 *	Released under GPLv3 license; see LICENSE. 
 **}
{* $Revision: 1.8 $ *}
{content_wrapper}
	{advanced_search}
	{input type="hidden" attribute="id" value=$transaction->id}
	{input type="hidden" attribute="type" value=$type}
	<p><strong>Budgets</strong></p>
	{paging}
	{data_table}
		{heading_row}
			{if $type=='centres'}
				{heading_cell field="centre" id=$transaction->id _type=$type}
					Centre
				{/heading_cell}
				{heading_cell field="account" id=$transaction->id _type=$type}
					Account
				{/heading_cell}
			{else}
				{heading_cell field="account" id=$transaction->id _type=$type}
					Account
				{/heading_cell}
				{heading_cell field="centre" id=$transaction->id _type=$type}
					Centre
				{/heading_cell}
			{/if}
			{heading_cell field="period" id=$transaction->id _type=$type}
				Period
			{/heading_cell}
			{heading_cell field="right" id=$transaction->id _type=$type}
				Value
			{/heading_cell}
		{/heading_row}
		{foreach name=datagrid item=model from=$glbudgets}
			{assign var=totalValue value=$totalValue+$model->value}
			{grid_row model=$model}
				{if $type=='centres'}
					{grid_cell model=$model cell_num=2 field="centre"}
						{$model->centre}
					{/grid_cell}
					{grid_cell model=$model cell_num=2 field="account"}
						{$model->account}
					{/grid_cell}
				{else}
					{grid_cell model=$model cell_num=2 field="account"}
						{$model->account}
					{/grid_cell}
					{grid_cell model=$model cell_num=2 field="centre"}
						{$model->centre}
					{/grid_cell}
				{/if}
				{grid_cell model=$model cell_num=2 field="periods"}
					{$model->periods}
				{/grid_cell}
				{grid_cell model=$model cell_num=1 field="value"}
					{$model->value|string_format:"%.2f"}
				{/grid_cell}
			{/grid_row}
		{foreachelse}
			<tr>
				<td colspan="0">No matching records found!</td>
			</tr>
		{/foreach}
		{if $glbudgets->count()>0}
			{grid_cell model=$model cell_num=2 }{/grid_cell}
			{grid_cell model=$model cell_num=2 }{/grid_cell}
			{grid_cell model=$model cell_num=2 }
				Total Value for Page
			{/grid_cell}
			{grid_cell model=$model cell_num=2 field="value"}
				{$totalValue|string_format:"%.2f"}
			{/grid_cell}
		{/if}
	{/data_table}
	{paging}
	<div id="data_grid_footer" class="clearfix">
		{include file='elements/data_table_actions.tpl'}
	</div>
{/content_wrapper}